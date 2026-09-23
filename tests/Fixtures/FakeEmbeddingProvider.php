<?php

namespace YetiSearch\Tests\Fixtures;

use YetiSearch\Contracts\EmbeddingProviderInterface;
use YetiSearch\Exceptions\EmbeddingException;

/**
 * Deterministic embeddings for tests. Each dimension is a concept, and a text
 * scores on a concept for every word it contains from that concept's list, so
 * "automobile" and "car" land close together while sharing no keyword.
 */
class FakeEmbeddingProvider implements EmbeddingProviderInterface
{
    public const CONCEPTS = [
        ['car', 'cars', 'automobile', 'automobiles', 'vehicle', 'vehicles', 'truck', 'sedan'],
        ['pizza', 'pasta', 'food', 'meal', 'recipe', 'dinner', 'cooking'],
        ['password', 'login', 'credentials', 'signin', 'account', 'locked'],
        ['rain', 'weather', 'forecast', 'storm', 'sunny'],
        ['guitar', 'music', 'song', 'chords', 'melody'],
    ];

    /** @var array<int, array{texts:string[], purpose:string}> */
    public array $calls = [];
    public bool $fail = false;
    /** Throw on any text that is not a string, as OpenAI-compatible APIs do. */
    public bool $strict = false;
    private string $model;

    public function __construct(string $model = 'fake-concepts')
    {
        $this->model = $model;
    }

    public function embed(array $texts, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        $this->calls[] = ['texts' => array_values($texts), 'purpose' => $purpose];
        if ($this->fail) {
            throw new EmbeddingException('Fake provider is down');
        }
        if ($this->strict) {
            foreach ($texts as $i => $text) {
                if (!is_string($text)) {
                    throw new EmbeddingException("HTTP 400: input[{$i}] expected string, received " . gettype($text));
                }
            }
        }

        $vectors = [];
        foreach ($texts as $text) {
            $words = preg_split('/[^a-z]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
            $vector = array_fill(0, count(self::CONCEPTS) + 1, 0.0);
            foreach ($words as $word) {
                foreach (self::CONCEPTS as $dim => $list) {
                    if (in_array($word, $list, true)) {
                        $vector[$dim] += 1.0;
                    }
                }
            }
            // A small constant keeps concept-free text from being a zero vector.
            $vector[count(self::CONCEPTS)] = 0.05;
            $vectors[] = $vector;
        }

        return $vectors;
    }

    public function dimensions(): int
    {
        return count(self::CONCEPTS) + 1;
    }

    public function modelId(): string
    {
        return $this->model;
    }

    public function queryCalls(): int
    {
        return count(array_filter($this->calls, function ($call) {
            return $call['purpose'] === self::PURPOSE_QUERY;
        }));
    }

    public function documentTextsEmbedded(): int
    {
        $n = 0;
        foreach ($this->calls as $call) {
            if ($call['purpose'] === self::PURPOSE_DOCUMENT) {
                $n += count($call['texts']);
            }
        }

        return $n;
    }
}
