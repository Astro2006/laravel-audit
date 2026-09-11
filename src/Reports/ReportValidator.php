<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

/**
 * Agent answer → report body → normalized → parsed → linted.
 */
final class ReportValidator
{
    public function __construct(
        private readonly ReportLinter $linter,
        private readonly ReportExtractor $extractor = new ReportExtractor,
        private readonly ReportNormalizer $normalizer = new ReportNormalizer,
        private readonly ReportParser $parser = new ReportParser,
    ) {}

    /** @param list<RequiredFinding> $required */
    public function validate(string $answer, ReportSpec $spec, array $required = []): ValidatedReport
    {
        $body = $this->normalizer->normalize($this->extractor->extract($answer));

        return new ValidatedReport($body, $this->linter->lint($this->parser->parse($body), $spec, $required));
    }
}
