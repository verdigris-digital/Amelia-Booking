<?php

namespace AmeliaHttp\Client\Common\Plugin;

use AmeliaHttp\Client\Common\Plugin;
use AmeliaHttp\Message\Encoding;
use AmeliaPsr\Http\Message\RequestInterface;
use AmeliaPsr\Http\Message\ResponseInterface;
use AmeliaPsr\Http\Message\StreamInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Allow to decode response body with a chunk, deflate, compress or gzip encoding.
 *
 * If zlib is not installed, only chunked encoding can be handled.
 *
 * If Content-Encoding is not disabled, the plugin will add an Accept-Encoding header for the encoding methods it supports.
 *
 * @author Joel Wurtz <joel.wurtz@gmail.com>
 */
final class DecoderPlugin implements Plugin
{
    /**
     * @var bool Whether this plugin decode stream with value in the Content-Encoding header (default to true).
     *
     * If set to false only the Transfer-Encoding header will be used
     */
    private $useContentEncoding;

    /**
     * @param array $config {
     *
     *    @var bool $use_content_encoding Whether this plugin should look at the Content-Encoding header first or only at the Transfer-Encoding (defaults to true).
     * }
     */
    public function __construct(array $config = [])
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'use_content_encoding' => true,
        ]);
        $resolver->setAllowedTypes('use_content_encoding', 'bool');
        $options = $resolver->resolve($config);

        $this->useContentEncoding = $options['use_content_encoding'];
    }

    /**
     * {@inheritdoc}
     */
    public function handleRequest(RequestInterface $request, callable $next, callable $first)
    {
        $encodings = extension_loaded('zlib') ? ['gzip', 'deflate'] : ['identity'];

        if ($this->useContentEncoding) {
            $request = $request->withHeader('Accept-Encoding', $encodings);
        }
        $encodings[] = 'chunked';
        $request = $request->withHeader('TE', $encodings);

        return $next($request)->then(function (ResponseInterface $response) {
            return $this->decodeResponse($response);
        });
    }

    /**
     * Decode a response body given its Transfer-Encoding or Content-Encoding value.
     *
     * @param ResponseInterface $response Response to decode
     *
     * @return ResponseInterface New response decoded
     */
    private function decodeResponse(ResponseInterface $response)
    {
        $response = $this->decodeOnEncodingHeader('Transfer-Encoding', $response);

        if ($this->useContentEncoding) {
            $response = $this->decodeOnEncodingHeader('Content-Encoding', $response);
        }

        return $response;
    }

    /**
     * Decode a response on a specific header (content encoding or transfer encoding mainly).
     *
     * @param string            $headerName Name of the header
     * @param ResponseInterface $response   Response
     *
     * @return ResponseInterface A new instance of the response decoded
     */
    private function decodeOnEncodingHeader($headerName, ResponseInterface $response)
    {
        if ($response->hasHeader($headerName)) {
            $encodings = $response->getHeader($headerName);
            $newEncodings = [];

            while ($encoding = array_pop($encodings)) {
                $stream = $this->decorateStream($encoding, $response->getBody());

                if (false === $stream) {
                    array_unshift($newEncodings, $encoding);

                    continue;
                }

                $response = $response->withBody($stream);
            }

            if (\count($newEncodings) > 0) {
                $response = $response->withHeader($headerName, $newEncodings);
            } else {
                $response = $response->withoutHeader($headerName);
            }
        }

        return $response;
    }

    /**
     * Decorate a stream given an encoding.
     *
     * @param string          $encoding
     * @param StreamInterface $stream
     *
     * @return StreamInterface|false A new stream interface or false if encoding is not supported
     */
    private function decorateStream($encoding, StreamInterface $stream)
    {
        if ('chunked' === strtolower($encoding)) {
            return new Encoding\DechunkStream($stream);
        }

        if ('deflate' === strtolower($encoding)) {
            return new Encoding\DecompressStream($stream);
        }

        if ('gzip' === strtolower($encoding)) {
            return new Encoding\GzipDecodeStream($stream);
        }

        return false;
    }
}
