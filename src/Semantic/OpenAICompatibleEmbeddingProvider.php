<?php

namespace YetiSearch\Semantic;

use YetiSearch\Contracts\EmbeddingProviderInterface;
use YetiSearch\Exceptions\EmbeddingException;

/**
 * Embeddings over the OpenAI /embeddings API, or any service that copies it.
 *
 * That covers OpenAI itself and, by pointing base_url elsewhere, OpenRouter
 * (https://openrouter.ai/api/v1), Ollama (http://localhost:11434/v1), LM
 * Studio, Voyage, Mistral, Together and most self-hosted embedding servers.
 *
 *     new OpenAICompatibleEmbeddingProvider([
 *         'api_key'    => getenv('OPENAI_API_KEY'),
 *         'model'      => 'text-embedding-3-small',
 *         'dimensions' => 512,
 *     ]);
 *
 *     new OpenAICompatibleEmbeddingProvider([
 *         'base_url'        => 'http://localhost:11434/v1',
 *         'model'           => 'nomic-embed-text',
 *         'query_prefix'    => 'search_query: ',
 *         'document_prefix' => 'search_document: ',
 *     ]);
 */
class OpenAICompatibleEmbeddingProvider implements EmbeddingProviderInterface
{
    private array $config;
    private int $detectedDimensions = 0;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => null,
            'model' => 'text-embedding-3-small',
            // Ask the model for shorter vectors. Only models that support it
            // (OpenAI text-embedding-3-*) should be given one; leave it null
            // for everything else and the length is learned from the first
            // response.
            'dimensions' => null,
            // Seconds. Indexing can wait; a search should not.
            'timeout' => 30,
            'query_timeout' => 5,
            // Texts per request. OpenAI accepts up to 2048.
            'batch_size' => 64,
            // Extra attempts for a document batch after a 429 or 5xx. Queries
            // never retry: falling back to keyword search beats a slow page.
            'max_retries' => 2,
            // Some models expect the two sides of a search to be marked:
            // nomic-embed-text wants "search_query: " and "search_document: ".
            'query_prefix' => '',
            'document_prefix' => '',
            // Send input_type: query|document, as Voyage expects.
            'input_type' => false,
            'headers' => [],
        ], $config);

        if (trim((string)$this->config['model']) === '') {
            throw new \InvalidArgumentException('An embedding model is required');
        }
    }

    public function embed(array $texts, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        $texts = array_values($texts);
        if (empty($texts)) {
            return [];
        }

        $isQuery = $purpose === self::PURPOSE_QUERY;
        $prefix = (string)($isQuery ? $this->config['query_prefix'] : $this->config['document_prefix']);
        if ($prefix !== '') {
            foreach ($texts as $i => $text) {
                $texts[$i] = $prefix . $text;
            }
        }

        $vectors = [];
        foreach (array_chunk($texts, max(1, (int)$this->config['batch_size'])) as $batch) {
            foreach ($this->request($batch, $isQuery) as $vector) {
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }

    public function dimensions(): int
    {
        return (int)($this->config['dimensions'] ?? 0) ?: $this->detectedDimensions;
    }

    public function modelId(): string
    {
        $id = (string)$this->config['model'];
        if (!empty($this->config['dimensions'])) {
            $id .= ':' . (int)$this->config['dimensions'];
        }
        // A prefix changes every vector the model produces.
        $prefixes = $this->config['query_prefix'] . "\0" . $this->config['document_prefix'];
        if ($prefixes !== "\0") {
            $id .= ':p' . substr(sha1($prefixes), 0, 8);
        }

        return $id;
    }

    /**
     * @param string[] $batch
     * @return array<int, float[]>
     */
    private function request(array $batch, bool $isQuery): array
    {
        $payload = [
            'model' => $this->config['model'],
            'input' => $batch,
        ];
        if (!empty($this->config['dimensions'])) {
            $payload['dimensions'] = (int)$this->config['dimensions'];
        }
        if ($this->config['input_type']) {
            $payload['input_type'] = $isQuery ? 'query' : 'document';
        }

        $url = rtrim((string)$this->config['base_url'], '/') . '/embeddings';
        $timeout = (float)($isQuery ? $this->config['query_timeout'] : $this->config['timeout']);
        $attempts = $isQuery ? 1 : 1 + max(0, (int)$this->config['max_retries']);

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if (!empty($this->config['api_key'])) {
            $headers[] = 'Authorization: Bearer ' . $this->config['api_key'];
        }
        foreach ((array)$this->config['headers'] as $name => $value) {
            $headers[] = is_int($name) ? (string)$value : $name . ': ' . $value;
        }

        $body = json_encode($payload);
        if ($body === false) {
            throw new EmbeddingException('Could not encode the embedding request: ' . json_last_error_msg());
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            [$status, $response] = $this->post($url, $headers, $body, $timeout);

            $retryable = $status === 429 || $status >= 500;
            if ($retryable && $attempt < $attempts) {
                usleep(1000000 * $attempt);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new EmbeddingException(sprintf(
                    'Embedding request to %s failed with HTTP %d: %s',
                    $url,
                    $status,
                    $this->errorMessage($response)
                ));
            }

            return $this->parse($response, count($batch));
        }
    }

    /**
     * @return array{0:int, 1:string} HTTP status and body
     */
    protected function post(string $url, array $headers, string $body, float $timeout): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => (int)min(5000, $timeout * 1000),
                CURLOPT_TIMEOUT_MS => (int)($timeout * 1000),
            ]);
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);

            if ($response === false) {
                throw new EmbeddingException("Embedding request to {$url} failed: {$error}");
            }

            return [$status, (string)$response];
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $stream = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            $error = error_get_last();
            throw new EmbeddingException("Embedding request to {$url} failed: " . ($error['message'] ?? 'no response'));
        }
        $meta = stream_get_meta_data($stream);
        $response = (string)stream_get_contents($stream);
        fclose($stream);

        $status = 0;
        foreach ((array)($meta['wrapper_data'] ?? []) as $line) {
            if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
            }
        }

        return [$status, $response];
    }

    /**
     * @return array<int, float[]>
     */
    private function parse(string $response, int $expected): array
    {
        $json = json_decode($response, true);
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            throw new EmbeddingException('Embedding response has no data list');
        }

        // Entries carry an index; do not rely on the order they arrive in.
        $vectors = [];
        foreach ($json['data'] as $position => $item) {
            $embedding = $item['embedding'] ?? null;
            if (!is_array($embedding) || empty($embedding)) {
                throw new EmbeddingException('Embedding response holds an entry with no vector');
            }
            $vectors[(int)($item['index'] ?? $position)] = array_map('floatval', $embedding);
        }
        ksort($vectors);
        $vectors = array_values($vectors);

        if (count($vectors) !== $expected) {
            throw new EmbeddingException(sprintf(
                'Embedding response holds %d vectors for %d texts',
                count($vectors),
                $expected
            ));
        }

        $this->detectedDimensions = count($vectors[0]);

        return $vectors;
    }

    private function errorMessage(string $response): string
    {
        $json = json_decode($response, true);
        $message = $json['error']['message'] ?? $json['error'] ?? $json['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }

        return substr(trim($response), 0, 200) ?: 'empty response';
    }
}
