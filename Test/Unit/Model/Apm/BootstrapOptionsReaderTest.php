<?php

declare(strict_types=1);

namespace MageZero\OpensearchObservability\Test\Unit\Model\Apm;

use MageZero\OpensearchObservability\Model\Apm\BootstrapOptionsReader;
use PHPUnit\Framework\TestCase;

class BootstrapOptionsReaderTest extends TestCase
{
    public function testReadReturnsEmptyArrayWhenFileMissing(): void
    {
        $reader = new BootstrapOptionsReader();
        $options = $reader->read([], '/tmp/this-file-should-not-exist-apm.php');

        $this->assertSame([], $options);
    }

    public function testReadNormalizesLegacyApmPhpOptions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'apm-options-');
        if ($path === false) {
            $this->fail('Unable to create temporary file');
        }

        file_put_contents(
            $path,
            <<<'PHP'
<?php
return [
    'serverUrl' => 'http://apm-server:8200',
    'enabled' => '1',
    'transactionSampleRate' => 2,
    'serviceName' => '',
    'hostname' => '',
    'environment' => '',
    'stackTraceLimit' => 0,
    'timeout' => 0,
    'secretToken' => 'secret-token',
    'serviceVersion' => '1.2.3',
];
PHP
        );

        $reader = new BootstrapOptionsReader();
        $options = $reader->read([
            'HTTP_HOST' => 'store.example.com:8443',
            'HOSTNAME' => 'app-node-1',
        ], $path);

        if (is_file($path)) {
            unlink($path);
        }

        $this->assertTrue($options['enabled']);
        $this->assertSame('http://apm-server:8200', $options['serverUrl']);
        $this->assertSame('store-example-com-8443', $options['serviceName']);
        $this->assertSame('app-node-1', $options['hostname']);
        $this->assertSame('production', $options['environment']);
        $this->assertSame(1.0, $options['transactionSampleRate']);
        $this->assertSame(1000, $options['stackTraceLimit']);
        $this->assertSame(10, $options['timeout']);
        $this->assertSame('secret-token', $options['secretToken']);
        $this->assertSame('1.2.3', $options['serviceVersion']);
        $this->assertSame('magento2', $options['frameworkName']);
    }

    public function testReadIgnoresNonArrayConfigPayload(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'apm-options-');
        if ($path === false) {
            $this->fail('Unable to create temporary file');
        }

        file_put_contents($path, "<?php\nreturn 'invalid';\n");
        $reader = new BootstrapOptionsReader();
        $options = $reader->read([], $path);
        if (is_file($path)) {
            unlink($path);
        }

        $this->assertSame([], $options);
    }
}
