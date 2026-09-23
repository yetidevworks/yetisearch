<?php

namespace YetiSearch\Exceptions;

/**
 * An embedding provider could not turn text into vectors: the endpoint was
 * unreachable, timed out, rejected the request, or answered with something
 * that is not a list of vectors.
 */
class EmbeddingException extends YetiSearchException
{
}
