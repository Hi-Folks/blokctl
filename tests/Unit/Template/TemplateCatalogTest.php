<?php

declare(strict_types=1);

namespace Tests\Unit\Template;

use Blokctl\Template\TemplateCatalog;
use Blokctl\Template\TemplateCatalogEntry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TemplateCatalogTest extends TestCase
{
    private const string DIRECTORY = 'tests/Fixtures/templates';

    #[Test]
    public function scans_valid_templates_and_reports_invalid_candidates(): void
    {
        $result = new TemplateCatalog()->scan(self::DIRECTORY);

        $this->assertSame(['Base Demo', 'Governance Demo', 'Retail Demo'], array_map(static fn(TemplateCatalogEntry $template): string => $template->name(), $result->templates));
        $this->assertCount(1, $result->errors);
        $this->assertStringEndsWith('invalid.yaml', $result->errors[0]['path']);
        $this->assertStringContainsString('Invalid setup config', $result->errors[0]['error']);
    }

    #[Test]
    public function loads_merged_template_details(): void
    {
        $template = new TemplateCatalog()->load(self::DIRECTORY . '/retail-demo.yaml');

        $this->assertSame('Retail Demo', $template->name());
        $this->assertSame('solutions-engineering', $template->metadata['owner']);
        $this->assertSame(['retail', 'multilingual'], $template->tags());
        $this->assertSame(['customer_name'], $template->inputs);
        $this->assertSame('duplicate', $template->targetMode);
        $this->assertSame(['base.yaml'], $template->extends);
    }

    #[Test]
    public function rejects_setup_files_without_template_metadata(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not declare template metadata');

        new TemplateCatalog()->load(self::DIRECTORY . '/not-a-template.yaml');
    }
}
