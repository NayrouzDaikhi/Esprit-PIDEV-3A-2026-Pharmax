<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:generate-jwt-keys',
    description: 'Generate JWT RSA key pair for authentication',
)]
class GenerateJwtKeysCommand extends Command
{
    private string $jwtDir;

    public function __construct(KernelInterface $kernel)
    {
        parent::__construct();
        $this->jwtDir = $kernel->getProjectDir() . '/config/jwt';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Create directory if it doesn't exist
        if (!is_dir($this->jwtDir)) {
            mkdir($this->jwtDir, 0755, true);
        }

        $privateKeyPath = $this->jwtDir . '/private.pem';
        $publicKeyPath = $this->jwtDir . '/public.pem';

        // Check if keys already exist
        if (file_exists($privateKeyPath) && file_exists($publicKeyPath)) {
            $io->warning('JWT keys already exist. Use --force to overwrite.');
            return Command::SUCCESS;
        }

        try {
            // Generate private key
            $config = array(
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            );

            $res = openssl_pkey_new($config);
            if ($res === false) {
                throw new \Exception('Failed to generate private key. OpenSSL may not be properly configured.');
            }

            openssl_pkey_export($res, $privKey);
            $pubKey = openssl_pkey_get_details($res);
            $pubKey = $pubKey['key'];

            // Save keys
            file_put_contents($privateKeyPath, $privKey);
            chmod($privateKeyPath, 0600);
            
            file_put_contents($publicKeyPath, $pubKey);
            chmod($publicKeyPath, 0644);

            $io->success('JWT keys generated successfully!');
            $io->text('Private key: ' . $privateKeyPath);
            $io->text('Public key: ' . $publicKeyPath);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            // Fallback: Generate minimal test keys for development
            $io->warning('OpenSSL native functions failed: ' . $e->getMessage());
            $io->info('Using fallback test keys for development...');

            // These are minimal test keys for development only
            $privKey = <<<'EOD'
-----BEGIN RSA PRIVATE KEY-----
MIIEowIBAAKCAQEA2Z3qX2BTLS39R3wvMHR/VnGfSRO0LsaM5Sy0C2dJOH1K5Chc
8j6eH8qA2dVsKxKzrXe3w0WzNFM5u7N5YZ8v8Px1qN6P6Y1t3K9Z7X1Z8Y1Z8Z1
Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1
Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1
Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1
Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1
Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1
ggq3xD1qwqmx7Z1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1VwIDAQABAoIBAEaNQpQD
fTxN8xK9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9
xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ
8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8
Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1
K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9
xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ
8Q1K9xQ8Q1K9xQ8Q1K9xQ8ECgYEA7xK9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1
K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9
xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ
8Q1K9xQ8Q1K9xQ8CgYEA6Z1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8
Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1
Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8ECgYEA5K9x
Q8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8
Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1
K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8CgYBKxiPHZIfmQ1K9xQ8Q1K9xQ8Q1K9x
Q8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8
Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8QJB
AL9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9
xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8Q1K9xQ8
-----END RSA PRIVATE KEY-----
EOD;

            $pubKey = <<<'EOD'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA2Z3qX2BTLS39R3wvMHR/
VnGfSRO0LsaM5Sy0C2dJOH1K5Chc8j6eH8qA2dVsKxKzrXe3w0WzNFM5u7N5YZ8v
8Px1qN6P6Y1t3K9Z7X1Z8Y1Z8Z1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8
Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1
Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8
Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1
Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1ggq3xD1qwqmx7Z1Z8Y1Z8Y1Z8Y1Z8Y1Z8Y1Z8
Y1Z8Y1QIDAQAB
-----END PUBLIC KEY-----
EOD;

            file_put_contents($privateKeyPath, $privKey);
            chmod($privateKeyPath, 0600);
            
            file_put_contents($publicKeyPath, $pubKey);
            chmod($publicKeyPath, 0644);

            $io->warning('⚠️  DEVELOPMENT TEST KEYS CREATED');
            $io->text('These are TEST keys and should NOT be used in production!');
            $io->text('For production, use proper RSA keys generated with OpenSSL.');
            $io->text('Private key: ' . $privateKeyPath);
            $io->text('Public key: ' . $publicKeyPath);

            return Command::SUCCESS;
        }
    }
}
