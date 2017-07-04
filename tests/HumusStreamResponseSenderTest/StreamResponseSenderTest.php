<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace HumusStreamResponseSenderTest;

use HumusStreamResponseSender\StreamResponseSender;
use PHPUnit\Framework\TestCase;
use Zend\Http\Response\Stream;

class StreamResponseSenderTest extends TestCase
{
    /**
     * @var \Zend\Mvc\ResponseSender\SendResponseEvent
     */
    protected $mockSendResponseEvent;

    /**
     * @var string
     */
    protected $testFile;

    /**
     * @var int
     */
    protected $fileSize;

    /**
     * @var array
     */
    protected $headers;

    /**
     * @var \Zend\Http\Request
     */
    protected $requestMock;

    protected function setUp()
    {
        $testFile = __DIR__ . '/TestAsset/sample-stream-file.txt';
        $this->testFile = $testFile;
        $fileSize = filesize($testFile);
        $this->fileSize = $fileSize;
        $basename = basename($testFile);
        $stream = fopen($testFile, 'rb');

        $headers = array(
            'Content-Disposition: attachment; filename="' . $basename . '"',
            'Content-Type: application/octet-stream',
        );

        $response = new Stream();
        $response->setStream($stream);
        $response->setContentLength($fileSize);
        $response->setStreamName($basename);
        $response->getHeaders()->addHeaders($headers);

        $headers = array_merge(
            $headers,
            array(
                'Content-Transfer-Encoding: binary',
                'Content-Length: ' . $fileSize
            )
        );
        $this->headers = $headers;

        $mockSendResponseEvent = $this->createMock('Zend\Mvc\ResponseSender\SendResponseEvent');
        $mockSendResponseEvent
            ->expects($this->any())
            ->method('getResponse')
            ->will($this->returnValue($response));

        $requestMock = $this->getMockForAbstractClass('Zend\Http\Request');

        $this->requestMock = $requestMock;
        $this->mockSendResponseEvent = $mockSendResponseEvent;
    }

    protected function validateSentHeaders(array $expected)
    {
        $sentHeaders = xdebug_get_headers();

        $diff = array_diff($sentHeaders, $expected);

        if (count($diff)) {
            $header = array_shift($diff);
            $this->assertContains('XDEBUG_SESSION', $header);
            $this->assertEquals(0, count($diff));
        }
    }

    /**
     * @runInSeparateProcess
     */
    public function testSendHeadersAndStreamInDefaultMode()
    {
        if (!function_exists('xdebug_get_headers')) {
            $this->markTestSkipped('Xdebug extension needed, skipped test');
        }

        $testFile = $this->testFile;

        $responseSender = new StreamResponseSender();
        $responseSender->setRequest($this->requestMock);

        ob_start();
        $responseSender($this->mockSendResponseEvent);
        $body = ob_get_clean();

        $this->assertEquals(file_get_contents($testFile), $body);

        $this->assertSame(200, $this->mockSendResponseEvent->getResponse()->getStatusCode());

        $this->headers[] = 'Accept-Ranges: none';
        $this->validateSentHeaders($this->headers);
    }

    /**
     * @runInSeparateProcess
     */
    public function testSendHeadersAndStreamWithEnabledRangeSupportWithoutRangeHeaders()
    {
        if (!function_exists('xdebug_get_headers')) {
            $this->markTestSkipped('Xdebug extension needed, skipped test');
        }

        $testFile = $this->testFile;

        $responseSender = new StreamResponseSender(
            array(
                'enable_range_support' => true
            )
        );
        $responseSender->setRequest($this->requestMock);

        ob_start();
        $responseSender($this->mockSendResponseEvent);
        $body = ob_get_clean();

        $this->assertEquals(file_get_contents($testFile), $body);

        $this->headers[] = 'Accept-Ranges: bytes';
        $this->headers[] = 'Content-Range: bytes 0-65/66';
        $this->validateSentHeaders($this->headers);
    }



    /**
     * @runInSeparateProcess
     */
    public function testSendHeadersAndStreamWithEnabledRangeSupportWithoutRangeHeadersAndChunkSize()
    {
        if (!function_exists('xdebug_get_headers')) {
            $this->markTestSkipped('Xdebug extension needed, skipped test');
        }

        $testFile = $this->testFile;

        $responseSender = new StreamResponseSender(
            array(
                'enable_range_support' => true,
                'chunk_size' => 10
            )
        );
        $responseSender->setRequest($this->requestMock);

        ob_start();
        $responseSender($this->mockSendResponseEvent);
        $body = ob_get_clean();

        $this->assertEquals(file_get_contents($testFile), $body);

        $this->headers[] = 'Accept-Ranges: bytes';
        $this->headers[] = 'Content-Range: bytes 0-65/66';
        $this->validateSentHeaders($this->headers);
    }

    /**
     * @runInSeparateProcess
     */
    public function testSendHeadersAndStreamWithEnabledRangeSupportWithInvalidRangeStartHeader()
    {
        $responseSender = new StreamResponseSender(
            array(
                'enable_range_support' => true
            )
        );

        $this->requestMock->getHeaders()->addHeaderLine('Range: bytes=6290368-');
        $responseSender->setRequest($this->requestMock);

        ob_start();
        $responseSender($this->mockSendResponseEvent);
        $body = ob_get_clean();

        $this->assertSame(416, $this->mockSendResponseEvent->getResponse()->getStatusCode());
        $this->assertSame('', $body);
    }

    /**
     * @runInSeparateProcess
     */
    public function testSendHeadersAndStreamWithEnabledRangeSupportWithInvalidRangeEndHeader()
    {
        $responseSender = new StreamResponseSender(
            array(
                'enable_range_support' => true
            )
        );

        $this->requestMock->getHeaders()->addHeaderLine('Range: bytes=1-37487329');
        $responseSender->setRequest($this->requestMock);

        ob_start();
        $responseSender($this->mockSendResponseEvent);
        $body = ob_get_clean();

        $this->assertSame(416, $this->mockSendResponseEvent->getResponse()->getStatusCode());
        $this->assertSame('', $body);
    }

    /**
     * @runInSeparateProcess
     */
    public function testSendHeadersAndStreamWithEnabledRangeSupportWithInvalidRangeStartAndEndHeader()
    {
        $responseSender = new StreamResponseSender(
            array(
                'enable_range_support' => true
            )
        );

        $this->requestMock->getHeaders()->addHeaderLine('Range: bytes=6290368-37487329');
        $responseSender->setRequest($this->requestMock);

        ob_start();
        $responseSender($this->mockSendResponseEvent);
        $body = ob_get_clean();

        $this->assertSame(416, $this->mockSendResponseEvent->getResponse()->getStatusCode());
        $this->assertSame('', $body);
    }

    /**
     * @runInSeparateProcess
     */
    public function testSendHeadersAndStreamWithEnabledRangeSupportWithInvalidRangeStartHeader2()
    {
        $responseSender = new StreamResponseSender(
            array(
                'enable_range_support' => true
            )
        );

        $this->requestMock->getHeaders()->addHeaderLine('Range: bytes=fkjdsfs-');
        $responseSender->setRequest($this->requestMock);

        ob_start();
        $responseSender($this->mockSendResponseEvent);
        $body = ob_get_clean();

        $this->assertSame(416, $this->mockSendResponseEvent->getResponse()->getStatusCode());
        $this->assertSame('', $body);
    }

    /**
     * @runInSeparateProcess
     */
    public function testSendHeadersAndStreamWithEnabledRangeSupportWithRangeHeader()
    {
        if (!function_exists('xdebug_get_headers')) {
            $this->markTestSkipped('Xdebug extension needed, skipped test');
        }

        $responseSender = new StreamResponseSender(
            array(
                'enable_range_support' => true,
                'chunk_size' => 10
            )
        );

        $this->requestMock->getHeaders()->addHeaderLine('Range: bytes=4-9');
        $responseSender->setRequest($this->requestMock);

        ob_start();
        $responseSender($this->mockSendResponseEvent);
        $body = ob_get_clean();

        $this->assertEquals(' is a ', $body);

        $basename = basename($this->testFile);
        $this->assertSame(206, $this->mockSendResponseEvent->getResponse()->getStatusCode());

        $this->validateSentHeaders(
            array(
                'Content-Disposition: attachment; filename="' . $basename . '"',
                'Content-Type: application/octet-stream',
                'Content-Transfer-Encoding: binary',
                'Content-Length: 6',
                'Accept-Ranges: bytes',
                'Content-Range: bytes 4-9/66'
            )
        );
    }

    /**
     * @runInSeparateProcess
     * @group by
     */
    public function testSendHeadersAndStreamWithEnabledRangeSupportWithRangeHeaderLastByte()
    {
        if (!function_exists('xdebug_get_headers')) {
            $this->markTestSkipped('Xdebug extension needed, skipped test');
        }

        $responseSender = new StreamResponseSender(
            array(
                'enable_range_support' => true,
                'chunk_size' => 10
            )
        );

        $this->requestMock->getHeaders()->addHeaderLine('Range: bytes=65-65');
        $responseSender->setRequest($this->requestMock);

        ob_start();
        $responseSender($this->mockSendResponseEvent);
        $body = ob_get_clean();

        $this->assertEquals('.', $body);

        $basename = basename($this->testFile);
        $this->assertSame(206, $this->mockSendResponseEvent->getResponse()->getStatusCode());

        $this->validateSentHeaders(
            array(
                'Content-Disposition: attachment; filename="' . $basename . '"',
                'Content-Type: application/octet-stream',
                'Content-Transfer-Encoding: binary',
                'Content-Length: 1',
                'Accept-Ranges: bytes',
                'Content-Range: bytes 65-65/66'
            )
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testSendHeadersAndStreamWithEnabledRangeSupportWithRangeHeaderOpenEnd()
    {
        if (!function_exists('xdebug_get_headers')) {
            $this->markTestSkipped('Xdebug extension needed, skipped test');
        }

        $responseSender = new StreamResponseSender(
            array(
                'enable_range_support' => true,
                'chunk_size' => 10
            )
        );

        $this->requestMock->getHeaders()->addHeaderLine('Range: bytes=6-');
        $responseSender->setRequest($this->requestMock);

        ob_start();
        $responseSender($this->mockSendResponseEvent);
        $body = ob_get_clean();

        $this->assertEquals('s a sample file that will be streamed during the unit tests.', $body);

        $basename = basename($this->testFile);

        $this->assertSame(206, $this->mockSendResponseEvent->getResponse()->getStatusCode());

        $this->validateSentHeaders(
            array(
                'Content-Disposition: attachment; filename="' . $basename . '"',
                'Content-Type: application/octet-stream',
                'Content-Transfer-Encoding: binary',
                'Content-Length: 60',
                'Accept-Ranges: bytes',
                'Content-Range: bytes 6-65/66'
            )
        );
    }

    /**
     * @runInSeparateProcess
     */
    public function testSpeedLimit()
    {
        $responseSender = new StreamResponseSender(
            array(
                'enable_speed_limit' => true,
                'chunk_size' => 40 // test should run 2 seconds
            )
        );

        $responseSender->setRequest($this->requestMock);

        $start = microtime(1);
        ob_start();
        $responseSender($this->mockSendResponseEvent);
        $body = ob_get_clean();
        $end = microtime(1) - $start;

        $this->assertEquals(file_get_contents($this->testFile), $body);
        $this->assertTrue($end > 2);
    }
}
