"""
Signs an Elektron escrow release PSBT with this Electrum wallet's own key,
bypassing the "Sign" button's refusal to touch a foreign multisig input.

WHY THIS EXISTS
Electrum's normal Sign button (Wallet.sign_transaction(), which the GUI
calls) refuses to sign any input whose address is not one it tracks as its
own -- see electrum/wallet.py, Abstract_Wallet.can_sign():

    # note: is_mine check needed to avoid false positives.
    #       just because keystore could sign, txin does not necessarily
    #       belong to wallet.
    if not self.is_mine(txin.address):
        continue

A 2-of-2 escrow address built fresh per order is, by definition, never
"one of this wallet's own addresses" -- even though this wallet's own key
is genuinely one of the two required signers. This is a deliberate
Electrum safety design, not a bug, but it means the normal Sign button can
never complete an Elektron escrow release. Until the underlying wallet
software (or a dedicated signing tool) supports this properly, this script
is the interim workaround.

It calls the KEYSTORE's own sign_transaction() directly instead of the
wallet's. That method (electrum/keystore.py, Software_KeyStore.
sign_transaction()) only checks "does one of the PSBT's embedded BIP32
derivation entries match a key I hold" -- exactly what the Sign button
checks internally too, just without the extra is_mine() gate on top. Your
private key is derived and used entirely inside this already-running,
already-unlocked Electrum process; nothing here reads, writes, or
transmits it anywhere.

HOW TO USE
1. Open this file in a plain text editor.
2. Paste the PSBT text from the order page (the unsigned one if you are
   the buyer, or the buyer-signed one if you are the seller) between the
   quotes for PSBT_TEXT below.
3. If your wallet has a password, put it between the quotes for
   WALLET_PASSWORD below; otherwise leave it as None.
4. Save the file. In Electrum, open Tools > Console and type (with your
   own file path):
       run(r"C:\\path\\to\\console-sign-release-psbt.py")
   (Electrum's console does not support interactive input() prompts,
   which is why the values are edited in the file itself rather than
   typed at a prompt.)
5. Copy the text printed after "COPY EVERYTHING BELOW THIS LINE:" back
   into the order page (the "paste your signed PSBT" box if you are the
   buyer). If you are the seller and the printed result says "ready to
   broadcast: True", that text is a complete, network-ready transaction
   -- broadcast it yourself from your own wallet (Tools > Load
   transaction). This tool never broadcasts anything on its own.
"""

PSBT_TEXT = "PASTE THE PSBT TEXT FROM THE ORDER PAGE HERE"
WALLET_PASSWORD = None  # replace with 'your-password' (in quotes) if your wallet has one

tx = electrum.transaction.tx_from_any(PSBT_TEXT)
before_have, needed = tx.signature_count()

for ks in wallet.get_keystores():
    ks.sign_transaction(tx, WALLET_PASSWORD)

after_have, needed = tx.signature_count()
result = tx.serialize()

print('')
print('Signatures before: {} / after: {} (out of {} needed in total across all inputs)'.format(before_have, after_have, needed))
if before_have == after_have:
    print('No new signature was added -- this wallet does not hold a matching key for this PSBT.')
print('Fully complete (ready to broadcast): {}'.format(tx.is_complete()))
print('')
print('COPY EVERYTHING BELOW THIS LINE:')
print(result)
