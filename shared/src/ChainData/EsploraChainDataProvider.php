<?php

namespace ElektronNet\Payments\Core\ChainData;

use RuntimeException;
use Throwable;

/**
 * Esplora/mempool-style REST API client (elektron-net-mempool, or a
 * compatible public instance).
 *
 * Deliberately does not paginate past the API's first page (up to ~25
 * confirmed transactions): every address this plugin ever queries is a
 * one-time escrow address, normally touched by a funding and a spending
 * transaction and nothing else. That is usually but not always exactly
 * one funding transaction, though -- observed for real: a buyer resuming a
 * stale, never-completed order (see the plugin README's "Open Questions")
 * paid the same address twice -- so getFundingOutputs() does not assume
 * there is only one; it is only the ~25-transaction page limit itself that
 * this assumes will never be hit for a single escrow address.
 */
final class EsploraChainDataProvider implements ChainDataProviderInterface
{
    /** @var string e.g. https://mempool.elektron-net.org/api */
    private $baseUrl;

    /** @var int seconds */
    private $timeoutSeconds;

    public function __construct(string $baseUrl, int $timeoutSeconds = 8)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function getAddressTransactions(string $address): array
    {
        $json = $this->getJson('/address/' . rawurlencode($address) . '/txs');

        $result = [];
        foreach ($json as $tx) {
            $received = 0;
            foreach ($tx['vout'] ?? [] as $vout) {
                if (($vout['scriptpubkey_address'] ?? null) === $address) {
                    $received += (int) ($vout['value'] ?? 0);
                }
            }
            $confirmations = 0;
            if (!empty($tx['status']['confirmed'])) {
                $confirmations = $this->getConfirmations($tx['txid']);
            }
            $result[] = new AddressTransaction($tx['txid'], $received, $confirmations);
        }

        return $result;
    }

    /**
     * Calls `GET {baseUrl}/address/{address}/utxo`, confirmed against
     * elektron-net-mempool's own backend/src/api/bitcoin/bitcoin.routes.ts
     * and esplora-api.interface.ts: response shape is
     * `[{txid, vout, status: {confirmed, ...}, value}]`, already limited to
     * outputs not yet spent -- unlike reconstructing this from `/txs` (this
     * method's previous implementation), which cannot tell an unspent
     * output from one a later transaction already consumed, and would wrongly
     * treat both the same. Only confirmed entries are returned: a release
     * transaction must not depend on an output that could still disappear
     * in a reorg (this plugin already requires
     * `required_confirmations` before an order even reaches `funded`, so
     * this is consistent with that, not stricter).
     */
    public function getFundingOutputs(string $address): array
    {
        $json = $this->getJson('/address/' . rawurlencode($address) . '/utxo');

        $result = [];
        foreach ($json as $utxo) {
            if (!empty($utxo['status']['confirmed'])) {
                $result[] = new FundingOutput($utxo['txid'], (int) $utxo['vout'], (int) $utxo['value']);
            }
        }

        return $result;
    }

    public function getConfirmations(string $txid): int
    {
        $status = $this->getJson('/tx/' . rawurlencode($txid) . '/status');
        if (empty($status['confirmed'])) {
            return 0;
        }

        $tipHeight = (int) $this->getJson('/blocks/tip/height');
        $txHeight = (int) ($status['block_height'] ?? 0);
        if ($txHeight <= 0) {
            return 0;
        }

        return max(0, $tipHeight - $txHeight + 1);
    }

    /**
     * Calls `GET {baseUrl}/v1/fees/recommended`, elektron-net-mempool's own
     * purpose-built fee endpoint (confirmed against that repository's
     * backend/src/api/bitcoin/bitcoin.routes.ts and
     * production/nginx/location-api.conf: the plain Esplora-compatible
     * `/fee-estimates` path exists too but is explicitly marked deprecated
     * there in favor of the `/v1/fees/...` endpoints, so this uses the
     * current one). Response shape:
     * `{fastestFee, halfHourFee, hourFee, economyFee, minimumFee}`, values
     * in lep/vByte (this fork keeps Bitcoin's satoshi-equivalent scale, so
     * the endpoint's sat/vByte numbers are lep/vByte numbers unchanged).
     * `halfHourFee` is used as the "moderate priority" rate this interface
     * asks for.
     */
    public function getFeeEstimateLepPerVByte(): ?int
    {
        try {
            $json = $this->getJson('/v1/fees/recommended');
        } catch (Throwable $e) {
            return null;
        }

        if (!is_array($json) || !isset($json['halfHourFee']) || !is_numeric($json['halfHourFee'])) {
            return null;
        }

        return max(1, (int) round((float) $json['halfHourFee']));
    }

    /**
     * @return array<mixed>
     */
    private function getJson(string $path)
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("Chain-data request failed ({$this->baseUrl}{$path}): curl error {$errno}.");
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("Chain-data request failed ({$this->baseUrl}{$path}): HTTP {$httpCode}.");
        }

        $decoded = json_decode($body, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Chain-data request returned invalid JSON ({$this->baseUrl}{$path}).");
        }

        return $decoded;
    }
}
