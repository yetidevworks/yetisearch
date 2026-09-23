<?php

namespace YetiSearch\Tests\Unit\Semantic;

use PHPUnit\Framework\TestCase;
use YetiSearch\Semantic\NoiseCalibration;

class NoiseCalibrationTest extends TestCase
{
    public function testDistributionOfKnownMargins(): void
    {
        $stats = NoiseCalibration::distribution([0.1, 0.2, 0.3, 0.4, 0.5]);

        $this->assertSame(5, $stats['count']);
        $this->assertSame(0.1, $stats['min']);
        $this->assertSame(0.3, $stats['median']);
        $this->assertSame(0.3, $stats['mean']);
        // Population deviation: sqrt(0.02) = 0.1414.
        $this->assertSame(0.1414, $stats['sd']);
        // 95th percentile, interpolated between 0.4 and 0.5 at 3.8.
        $this->assertSame(0.48, $stats['p95']);
        $this->assertSame(0.5, $stats['max']);
    }

    public function testDistributionOfNothing(): void
    {
        $this->assertSame(0, NoiseCalibration::distribution([])['count']);
        $this->assertSame(0.0, NoiseCalibration::ceiling([]));
    }

    public function testCeilingIsTheMeanPlusTwoDeviations(): void
    {
        // Mean 0.3, sd 0.1414: 0.3 + 2 * 0.1414.
        $this->assertSame(0.5828, NoiseCalibration::ceiling([0.1, 0.2, 0.3, 0.4, 0.5]));
        // Identical margins have no spread: the ceiling is the margin itself.
        $this->assertSame(0.12, NoiseCalibration::ceiling([0.12, 0.12, 0.12]));
        // Clamped to [0, 1].
        $this->assertSame(1.0, NoiseCalibration::ceiling([0.9, 1.4]));
    }

    public function testMeasureAsksTheGatesQuestionPerProbe(): void
    {
        // Four documents on the axes; a probe halfway between x and y.
        $documents = [[1.0, 0.0], [0.0, 1.0], [-1.0, 0.0], [0.0, -1.0]];
        $probe = [sqrt(0.5), sqrt(0.5)];

        $measured = NoiseCalibration::measure(['p' => $probe, 'x' => [1.0, 0.0]], $documents, $read);

        $this->assertSame(4, $read);
        // Sorted similarities for p: -0.7071, -0.7071, 0.7071, 0.7071; median is index intdiv(4, 2) = 2.
        $this->assertSame(['best' => 0.7071, 'median' => 0.7071, 'margin' => 0.0], $measured['p']);
        // For x: -1, 0, 0, 1; median 0.
        $this->assertSame(['best' => 1.0, 'median' => 0.0, 'margin' => 1.0], $measured['x']);
    }

    public function testMeasureReadsAGenerator(): void
    {
        $generator = (function () {
            for ($i = 0; $i < 3; $i++) {
                yield [1.0, 0.0];
            }
        })();

        $measured = NoiseCalibration::measure(['x' => [1.0, 0.0]], $generator, $read);

        $this->assertSame(3, $read);
        $this->assertSame(0.0, $measured['x']['margin']);
    }

    public function testFromMeasurementAppliesTheRule(): void
    {
        $measured = [];
        foreach ([0.1, 0.2, 0.3, 0.4, 0.5] as $i => $margin) {
            $measured["p{$i}"] = ['best' => 0.5, 'median' => 0.5 - $margin, 'margin' => $margin];
        }

        $calibration = NoiseCalibration::fromMeasurement('m', 512, 40, '', [], $measured, 1000);

        $this->assertSame(0.5828, $calibration->minMargin());
        $this->assertSame(40, $calibration->documents());
        $this->assertSame(512, $calibration->dimensions());
        $this->assertSame(1000, $calibration->measuredAt());
        $this->assertSame(NoiseCalibration::probeSet(), $calibration->probeSetVersion());
        $this->assertSame(0.3, $calibration->stats()['mean']);
    }

    public function testProbeSetIsStableAndLargeEnough(): void
    {
        $this->assertSame(NoiseCalibration::probeSet(), NoiseCalibration::probeSet());
        $this->assertSame(12, strlen(NoiseCalibration::probeSet()));
        $this->assertCount(88, NoiseCalibration::PROBES);
        $this->assertCount(88, array_unique(NoiseCalibration::PROBES));
        // The strings used to check results by hand stay out of the probes.
        foreach (['asdf', 'qwerty', 'lorem ipsum', 'xyzzy', 'zxcv', 'hjkl', 'blorft'] as $honest) {
            $this->assertNotContains($honest, NoiseCalibration::PROBES);
        }
    }

    public function testMatchesAndDrift(): void
    {
        $filters = [['field' => 'type', 'value' => 'card']];
        $calibration = new NoiseCalibration('m', 512, 100, NoiseCalibration::probeSet(), 'a {query}', $filters, [], [], 0.2, 1);

        $this->assertTrue($calibration->matches('m', 512, 'a {query}', $filters));
        // Dimensions unknown to the provider (0) are not held against it.
        $this->assertTrue($calibration->matches('m', 0, 'a {query}', $filters));
        $this->assertFalse($calibration->matches('other', 512, 'a {query}', $filters));
        $this->assertFalse($calibration->matches('m', 256, 'a {query}', $filters));
        $this->assertFalse($calibration->matches('m', 512, '', $filters));
        $this->assertFalse($calibration->matches('m', 512, 'a {query}', []));

        $this->assertFalse($calibration->drifted(125));
        $this->assertFalse($calibration->drifted(75));
        $this->assertTrue($calibration->drifted(126));
        $this->assertTrue($calibration->drifted(74));

        $this->assertTrue($calibration->applies('m', 512, 'a {query}', $filters, 110));
        $this->assertFalse($calibration->applies('m', 512, 'a {query}', $filters, 200));

        $stale = new NoiseCalibration('m', 512, 100, 'old-probe-set', '', [], [], [], 0.2, 1);
        $this->assertFalse($stale->matches('m', 512));
    }

    public function testArrayRoundTripThroughJson(): void
    {
        $measured = ['vbrtk' => ['best' => 0.5, 'median' => 0.0, 'margin' => 0.5], 'fnord' => ['best' => 0.3, 'median' => 0.1, 'margin' => 0.2]];
        $calibration = NoiseCalibration::fromMeasurement('m', 8, 12, 'a {query}', [['field' => 'type', 'value' => 'card']], $measured, 99);

        $restored = NoiseCalibration::fromArray(json_decode((string)json_encode($calibration->toArray()), true));

        $this->assertNotNull($restored);
        $this->assertSame($calibration->toArray(), $restored->toArray());
        $this->assertSame(0.0, $restored->probes()['vbrtk']['median']);
        $this->assertNull(NoiseCalibration::fromArray(['model' => 'm']));
    }
}
