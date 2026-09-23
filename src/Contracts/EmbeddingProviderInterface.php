<?php

namespace YetiSearch\Contracts;

use YetiSearch\Exceptions\EmbeddingException;

/**
 * Turns text into vectors for semantic search.
 *
 * Implement this to plug any embedding service into YetiSearch. The library
 * ships OpenAICompatibleEmbeddingProvider, which covers OpenAI and anything
 * that speaks the same /embeddings API (Ollama, LM Studio, Voyage, Mistral,
 * Together and others).
 */
interface EmbeddingProviderInterface
{
    /** The texts are documents being indexed. */
    public const PURPOSE_DOCUMENT = 'document';

    /** The text is a search query. */
    public const PURPOSE_QUERY = 'query';

    /**
     * Embed a list of texts.
     *
     * Some models embed queries and documents differently (Voyage's
     * input_type, nomic's "search_query:" prefix). $purpose says which one
     * this call is for; a provider whose model makes no distinction ignores it.
     *
     * @param string[] $texts
     * @param string $purpose One of the PURPOSE_* constants
     * @return array<int, float[]> One vector per input text, in input order
     * @throws EmbeddingException
     */
    public function embed(array $texts, string $purpose = self::PURPOSE_DOCUMENT): array;

    /**
     * Length of the vectors this provider returns, or 0 when it is only known
     * after the first call.
     */
    public function dimensions(): int;

    /**
     * Identifies the model and anything else that changes the vectors, such
     * as the dimensions. Vectors stored under one id are never compared with
     * a query embedded under another, and changing it marks every stored
     * vector for re-embedding.
     */
    public function modelId(): string;
}
