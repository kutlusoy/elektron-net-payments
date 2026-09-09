<?php

namespace ElektronNet\Payments\Core\Escrow;

use BitWasp\Bitcoin\Address\SegwitAddress;
use BitWasp\Bitcoin\Crypto\Hash;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Script\WitnessProgram;

/**
 * Derives a fresh, order-specific child public key from a user's
 * registered xpub, instead of the platform ever reusing the same
 * registered key across multiple orders -- see
 * PlainMultisigEscrowScriptBuilder's docblock for why that guarantee moved
 * out of the script and into key derivation.
 *
 * Fixed scheme: external chain, i.e. `<xpub>/0/<index>` -- the same
 * convention a standard Electrum wallet uses internally for its own
 * receiving addresses (confirmed against elektron-net-electrum's own
 * source: Deterministic_Wallet.derive_pubkeys(c, i) with c=0 for the
 * receiving/external chain), so that a technical user can independently
 * re-derive and cross-check a given index with their own wallet's tooling
 * if they ever need to. $index must never repeat for the same xpub (see
 * EscrowWalletDAO's next_index counter) -- reusing an index reuses the
 * exact same escrow address, breaking the one-address-per-order guarantee
 * this class exists to provide.
 *
 * A wallet whose registered xpub does not actually sit at the expected
 * "one level above the receiving chain" depth (observed in the wild on at
 * least one real node wallet, where an xpub returned by `gethdkeys` was at
 * the master level instead of the account level) will derive a child key
 * the wallet itself cannot recognize as its own. That is exactly why the
 * wallet-connect flow verifies a derived address against one the user
 * actually recognizes before saving the xpub (see includes/wallet.php) --
 * this class does not, and cannot, detect that failure mode by itself.
 */
final class XpubChildKeyDeriver
{
    /** @var HierarchicalKeyFactory */
    private $factory;

    public function __construct()
    {
        $this->factory = new HierarchicalKeyFactory();
    }

    public function deriveChildPubKeyHex(string $xpubBase58, int $index, Network $network): string
    {
        $account = $this->factory->fromExtended($xpubBase58, $network);
        $child = $account->derivePath('0/' . $index);

        return $child->getPublicKey()->getBuffer()->getHex();
    }

    /**
     * The plain P2WPKH address for a derived child key -- used only for
     * the connect-time "does this look familiar?" check, never for the
     * actual 2-of-2 escrow address (that comes from
     * EscrowScriptBuilderInterface instead).
     */
    public function deriveChildAddress(string $xpubBase58, int $index, Network $network): string
    {
        $account = $this->factory->fromExtended($xpubBase58, $network);
        $child = $account->derivePath('0/' . $index);
        $hash160 = Hash::sha256ripe160($child->getPublicKey()->getBuffer());

        return (new SegwitAddress(WitnessProgram::v0($hash160)))->getAddress($network);
    }
}
