<?php

namespace YetiSearch\Semantic;

/**
 * Where a noise calibration and the probes' vectors are kept.
 *
 * Two things, stored differently on purpose. A calibration belongs to one
 * index: it describes that index's documents under one model. The probe
 * vectors belong to a model and a query frame and to nothing else, so every
 * index that uses the model shares them, and they survive a rebuild or a
 * dropIndex() of any of them.
 *
 * SqliteStorage implements it with two tables in the index's own database
 * (yetisearch_calibration and yetisearch_probe_embeddings). A caller that
 * wants the records somewhere else (JSON files beside an index that gets
 * deleted on rebuild, say) implements it and hands it to
 * SemanticSearch::setCalibrationStore().
 */
interface CalibrationStore
{
    /**
     * The calibration last saved for an index, as NoiseCalibration::toArray()
     * wrote it, or null.
     *
     * @return array<string, mixed>|null
     */
    public function loadCalibration(string $index): ?array;

    /**
     * @param array<string, mixed> $record NoiseCalibration::toArray()
     */
    public function saveCalibration(string $index, array $record): void;

    public function deleteCalibration(string $index): void;

    /**
     * The cached probe vectors for a model id and query frame, by the text
     * that was embedded.
     *
     * @return array<string, float[]>
     */
    public function loadProbeVectors(string $model, string $frame = ''): array;

    /**
     * Add probe vectors to a model's cache.
     *
     * @param array<string, float[]> $vectors embedded text => normalized vector
     */
    public function saveProbeVectors(string $model, string $frame, array $vectors): void;
}
