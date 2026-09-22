<?php

declare(strict_types=1);

namespace Astro2006\LaravelAudit\Reports;

/**
 * Agent answer → report body → normalized → parsed → linted; rejected
 * findings are removed from the body.
 */
final class ReportValidator
{
    public function __construct(
        private readonly ReportLinter $linter,
        private readonly ReportExtractor $extractor = new ReportExtractor,
        private readonly ReportNormalizer $normalizer = new ReportNormalizer,
        private readonly ReportParser $parser = new ReportParser,
        private readonly FindingEditor $editor = new FindingEditor,
    ) {}

    public static function forProject(string $basePath): self
    {
        return new self(new ReportLinter($basePath));
    }

    /** @param list<RequiredFinding> $required */
    public function validate(string $answer, ReportSpec $spec, array $required = []): ValidatedReport
    {
        $body = $this->normalizer->normalize($this->extractor->extract($answer));
        $lint = $this->linter->lint($this->parser->parse($body), $spec, $required);

        // Rejected findings leave the body too, so the audit report never shows them.
        $rejected = array_map(fn (Rejection $rejection): string => $rejection->id, $lint->rejected);

        return new ValidatedReport($this->editor->remove($body, $rejected), $lint);
    }
}
