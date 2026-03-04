<?php
$config = array(
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
);

$res = openssl_pkey_new($config);
openssl_pkey_export($res, $privKey);
$pubKey = openssl_pkey_get_details($res);
$pubKey = $pubKey['key'];

file_put_contents('config/jwt/private.pem', $privKey);
file_put_contents('config/jwt/public.pem', $pubKey);

echo "Keys generated successfully\n";
echo "Private key size: " . strlen($privKey) . " bytes\n";
echo "Public key size: " . strlen($pubKey) . " bytes\n";
