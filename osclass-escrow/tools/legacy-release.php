<?php
/**
 * One-time recovery tool for orders created with the current nonce-prefixed
 * escrow script (see shared/src/Escrow/BitwaspEscrowScriptBuilder.php).
 *
 * WHY THIS EXISTS: that script cannot be signed by the real Elektron Net
 * wallet (its signing flow only recognizes plain descriptor-based scripts,
 * not this nested-conditional one -- confirmed by reading that wallet's own
 * source), and it *also* cannot be signed by bitwasp/bitcoin's own
 * high-level Signer/InputSigner API, even with allowComplexScripts(true):
 * the script's leading `<orderNonce> OP_DROP` (present before the very
 * first OP_IF) does not match any of InputSigner's known step templates
 * (Multisig, PayToPubkey, PayToPubkeyHash, CLTV, CSV), so
 * InputSigner::extractScript() throws "Invalid script" before it ever gets
 * to the cooperative branch. Confirmed by actually running it, not assumed.
 *
 * This tool instead builds the BIP143 (segwit v0) sighash directly
 * (BitWasp\Bitcoin\Script\Interpreter\Checker::getSigHash, the same code
 * consensus verification itself uses) and assembles the witness stack by
 * hand for the script's cooperative (2-of-2) branch. The result is
 * independently re-verified against BitWasp's own Interpreter before it is
 * ever printed, so a mistake here fails loudly instead of producing a
 * transaction that looks fine but doesn't actually spend.
 *
 * USAGE (three steps, run as CLI, never through the web server):
 *   1. php legacy-release.php skeleton --address=... --redeem-script=... --destination=... --amount-lep=...
 *      [--buyer-pubkey=... --seller-pubkey=...] writes skeleton.json (no
 *      private key involved). The two pubkeys are optional but let step 2
 *      catch someone entering the wrong wallet's private key.
 *   2. Buyer and seller each separately run, on their OWN machine:
 *      php legacy-release.php sign --skeleton=skeleton.json --role=buyer
 *      php legacy-release.php sign --skeleton=skeleton.json --role=seller
 *      Each is prompted for their WIF private key (not echoed, never
 *      written to disk) and prints a signature -- not secret, but still
 *      only share it with the person combining the transaction.
 *   3. php legacy-release.php combine --skeleton=skeleton.json --buyer-sig=... --seller-sig=... [--broadcast]
 *      prints the final raw transaction hex (and broadcasts it if asked).
 *
 * Delete this file (and the whole tools/ directory) once the recovery is
 * done. It is deliberately not wired into any Osclass route or admin page:
 * an admin-page version would mean private key material passing through
 * the live web server (request bodies, PHP error logs, hosting-provider
 * access) at least once, however briefly. A private key never has to leave
 * the machine it is typed into if this only ever runs as a local CLI
 * script.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This tool only runs from the command line.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Crypto\EcAdapter\EcSerializer;
use BitWasp\Bitcoin\Crypto\EcAdapter\Serializer\Signature\DerSignatureSerializerInterface;
use BitWasp\Bitcoin\Key\Factory\PrivateKeyFactory;
use BitWasp\Bitcoin\Script\Interpreter\Checker;
use BitWasp\Bitcoin\Script\Interpreter\Interpreter;
use BitWasp\Bitcoin\Script\Script;
use BitWasp\Bitcoin\Script\ScriptWitness;
use BitWasp\Bitcoin\Script\WitnessScript;
use BitWasp\Bitcoin\Serializer\Signature\TransactionSignatureSerializer;
use BitWasp\Bitcoin\Serializer\Transaction\TransactionOutPointSerializer;
use BitWasp\Bitcoin\Signature\TransactionSignature;
use BitWasp\Bitcoin\Transaction\Factory\TxBuilder;
use BitWasp\Bitcoin\Transaction\SignatureHash\SigHash;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Buffertools\Buffer;

function fail(string $message): void
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

/** @return array<string,string> */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 2) as $arg) {
        if (strpos($arg, '--') === 0 && strpos($arg, '=') !== false) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $out[$key] = $value;
        } elseif (strpos($arg, '--') === 0) {
            $out[substr($arg, 2)] = '1';
        }
    }
    return $out;
}

function readSecret(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    if (stripos(PHP_OS, 'WIN') === 0) {
        return trim((string) fgets(STDIN));
    }
    shell_exec('stty -echo');
    $value = trim((string) fgets(STDIN));
    shell_exec('stty echo');
    fwrite(STDOUT, "\n");
    return $value;
}

function httpJson(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) {
        fail("request to {$url} failed (HTTP {$code}).");
    }
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        fail("request to {$url} returned invalid JSON.");
    }
    return $decoded;
}

/** Native P2WSH: scriptSig is always empty; the witness carries everything. */
function witnessOutputScript(WitnessScript $witnessScript): Script
{
    return $witnessScript->getOutputScript();
}

function cmdSkeleton(array $args): void
{
    foreach (['address', 'redeem-script', 'destination', 'amount-lep'] as $required) {
        if (!isset($args[$required])) {
            fail("--{$required} is required.");
        }
    }
    // buyer-pubkey/seller-pubkey are optional but let 'sign' warn if someone
    // enters the wrong private key later.

    $mempoolApi = rtrim($args['mempool-api'] ?? 'https://mempool.elektron-net.org/api', '/');
    $witnessScript = new WitnessScript(new Script(Buffer::hex($args['redeem-script'])));

    $addrCreator = new AddressCreator();
    $derivedAddress = $witnessScript->getAddress()->getAddress();
    if ($derivedAddress !== $args['address']) {
        fail(
            "the redeem script does not derive to --address. Got {$derivedAddress}, " .
            "expected {$args['address']}. Double-check redeem_script_hex and s_address " .
            "were copied from the same order row."
        );
    }

    $utxos = httpJson($mempoolApi . '/address/' . rawurlencode($args['address']) . '/utxo');
    $confirmed = array_values(array_filter($utxos, static function (array $u): bool {
        return !empty($u['status']['confirmed']);
    }));
    if (count($confirmed) === 0) {
        fail('no confirmed UTXO found at this address. Nothing to spend, or not confirmed yet.');
    }
    if (count($confirmed) > 1) {
        fail(
            'more than one confirmed UTXO at this address -- this tool only handles the ' .
            'single-funding-tx case every escrow order is expected to have. Resolve manually.'
        );
    }
    $utxo = $confirmed[0];

    $destination = $addrCreator->fromString($args['destination']);
    $amountOut = (int) $args['amount-lep'];
    $fee = (int) $utxo['value'] - $amountOut;
    if ($fee < 0) {
        fail("--amount-lep ({$amountOut}) exceeds the UTXO value ({$utxo['value']}).");
    }

    $skeleton = [
        'address' => $args['address'],
        'redeem_script_hex' => $args['redeem-script'],
        'funding_txid' => $utxo['txid'],
        'funding_vout' => (int) $utxo['vout'],
        'funding_value_lep' => (int) $utxo['value'],
        'destination' => $args['destination'],
        'amount_out_lep' => $amountOut,
        'mempool_api' => $mempoolApi,
        'buyer_pubkey' => $args['buyer-pubkey'] ?? null,
        'seller_pubkey' => $args['seller-pubkey'] ?? null,
    ];

    $path = $args['out'] ?? 'skeleton.json';
    file_put_contents($path, json_encode($skeleton, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    echo "Wrote {$path}\n";
    echo "Funding UTXO: {$utxo['txid']}:{$utxo['vout']} ({$utxo['value']} lep)\n";
    echo "Sending {$amountOut} lep to {$args['destination']}, fee {$fee} lep.\n";
    echo "Give this file to both buyer and seller so they can run 'sign' against the exact same transaction.\n";
}

/** @return array{0: \BitWasp\Bitcoin\Transaction\TransactionInterface, 1: WitnessScript, 2: int} */
function buildUnsignedFromSkeleton(array $skeleton)
{
    $witnessScript = new WitnessScript(new Script(Buffer::hex($skeleton['redeem_script_hex'])));
    $addrCreator = new AddressCreator();

    $unsigned = (new TxBuilder())
        ->input($skeleton['funding_txid'], (int) $skeleton['funding_vout'], null, 0xffffffff)
        ->payToAddress((int) $skeleton['amount_out_lep'], $addrCreator->fromString($skeleton['destination']))
        ->get();

    return [$unsigned, $witnessScript, (int) $skeleton['funding_value_lep']];
}

function cmdSign(array $args): void
{
    foreach (['skeleton', 'role'] as $required) {
        if (!isset($args[$required])) {
            fail("--{$required} is required.");
        }
    }
    if (!in_array($args['role'], ['buyer', 'seller'], true)) {
        fail("--role must be 'buyer' or 'seller'.");
    }

    $skeleton = json_decode((string) file_get_contents($args['skeleton']), true);
    if (!is_array($skeleton)) {
        fail("could not read skeleton file {$args['skeleton']}.");
    }

    echo "About to sign as {$args['role']}:\n";
    echo "  Spending {$skeleton['funding_txid']}:{$skeleton['funding_vout']} ({$skeleton['funding_value_lep']} lep)\n";
    echo "  Sending {$skeleton['amount_out_lep']} lep to {$skeleton['destination']}\n";
    echo "Verify these details match what you expect BEFORE entering your private key.\n\n";

    $wif = readSecret('Private key (WIF), not shown: ');
    if ($wif === '') {
        fail('no private key entered.');
    }

    [$unsigned, $witnessScript, $fundingValue] = buildUnsignedFromSkeleton($skeleton);

    $ecAdapter = Bitcoin::getEcAdapter();
    $factory = new PrivateKeyFactory();
    $privateKey = $factory->fromWif($wif);

    $checker = new Checker($ecAdapter, $unsigned, 0, $fundingValue);
    $hash = $checker->getSigHash($witnessScript, SigHash::ALL, SigHash::V1);

    $sigSerializer = new TransactionSignatureSerializer(
        EcSerializer::getSerializer(DerSignatureSerializerInterface::class, true, $ecAdapter)
    );
    $txSignature = new TransactionSignature($ecAdapter, $privateKey->sign($hash), SigHash::ALL);
    $sigHex = $sigSerializer->serialize($txSignature)->getHex();

    $expectedPubKeyIndex = $args['role'] === 'buyer' ? 'buyer_pubkey' : 'seller_pubkey';
    echo "\nSignature ({$args['role']}):\n{$sigHex}\n\n";
    echo "Send this signature to whoever runs the 'combine' step. It cannot be used to\n";
    echo "spend anything by itself and does not reveal your private key.\n";
    if (isset($skeleton[$expectedPubKeyIndex])) {
        $derivedPub = $privateKey->getPublicKey()->getBuffer()->getHex();
        if (strtolower($derivedPub) !== strtolower((string) $skeleton[$expectedPubKeyIndex])) {
            echo "\nWARNING: the public key derived from this private key does not match the\n";
            echo "order's stored {$expectedPubKeyIndex}. Double-check you entered the right key.\n";
        }
    }
}

function cmdCombine(array $args): void
{
    foreach (['skeleton', 'buyer-sig', 'seller-sig'] as $required) {
        if (!isset($args[$required])) {
            fail("--{$required} is required.");
        }
    }

    $skeleton = json_decode((string) file_get_contents($args['skeleton']), true);
    if (!is_array($skeleton)) {
        fail("could not read skeleton file {$args['skeleton']}.");
    }

    [$unsigned, $witnessScript, $fundingValue] = buildUnsignedFromSkeleton($skeleton);

    $witness = new ScriptWitness(
        new Buffer(''), // OP_CHECKMULTISIG's off-by-one dummy element
        Buffer::hex($args['buyer-sig']),
        Buffer::hex($args['seller-sig']),
        new Buffer("\x01"), // select the outer OP_IF (cooperative) branch
        $witnessScript->getBuffer()
    );

    $ecAdapter = Bitcoin::getEcAdapter();
    $checker = new Checker($ecAdapter, $unsigned, 0, $fundingValue);
    $interpreter = new Interpreter($ecAdapter);
    $flags = Interpreter::VERIFY_WITNESS | Interpreter::VERIFY_P2SH | Interpreter::VERIFY_DERSIG
        | Interpreter::VERIFY_LOW_S | Interpreter::VERIFY_STRICTENC;

    $spk = witnessOutputScript($witnessScript);
    $valid = $interpreter->verify(new Script(), $spk, $flags, $checker, $witness);
    if (!$valid) {
        fail(
            "the assembled witness does NOT verify against the real script interpreter. " .
            "Refusing to print a transaction. Check that both signatures were produced " .
            "against this exact skeleton.json (same funding UTXO, same destination, same amount)."
        );
    }

    $signedInput = $unsigned->getInput(0);
    $signedTx = new \BitWasp\Bitcoin\Transaction\Transaction(
        $unsigned->getVersion(),
        [$signedInput],
        $unsigned->getOutputs(),
        [$witness],
        $unsigned->getLockTime()
    );

    $rawHex = $signedTx->getHex();
    echo "Witness verified OK against the interpreter.\n\n";
    echo "Raw signed transaction:\n{$rawHex}\n";

    if (isset($args['broadcast'])) {
        $ch = curl_init(rtrim($skeleton['mempool_api'], '/') . '/tx');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rawHex,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo "\nBroadcast response (HTTP {$code}):\n{$response}\n";
    } else {
        echo "\nNot broadcast (pass --broadcast to send it, or paste the hex into any\n";
        echo "'broadcast raw transaction' tool yourself).\n";
    }
}

$command = $argv[1] ?? '';
$args = parseArgs($argv);

switch ($command) {
    case 'skeleton':
        cmdSkeleton($args);
        break;
    case 'sign':
        cmdSign($args);
        break;
    case 'combine':
        cmdCombine($args);
        break;
    default:
        fwrite(STDERR, "Usage: php legacy-release.php <skeleton|sign|combine> --option=value ...\n");
        fwrite(STDERR, "See the docblock at the top of this file for the full walkthrough.\n");
        exit(1);
}
