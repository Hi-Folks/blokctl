<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Blokctl\Command\TemplateFindCommand;
use Blokctl\Command\TemplateListCommand;
use Blokctl\Command\TemplateShowCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

final class TemplateCommandsTest extends TestCase
{
    private const string DIRECTORY = 'tests/Fixtures/templates';

    #[Test]
    public function list_returns_templates_and_non_fatal_errors_as_json(): void
    {
        $tester = new CommandTester(new TemplateListCommand());

        $this->assertSame(0, $tester->execute(['directory' => self::DIRECTORY, '--json' => true]));
        $result = $this->json($tester);

        $this->assertIsArray($result['templates']);
        $this->assertIsArray($result['errors']);
        $this->assertCount(3, $result['templates']);
        $this->assertCount(1, $result['errors']);
    }

    #[Test]
    public function list_uses_readable_bullets_for_console_output(): void
    {
        $tester = new CommandTester(new TemplateListCommand());

        $this->assertSame(0, $tester->execute(['directory' => self::DIRECTORY]));
        $display = $tester->getDisplay();

        $this->assertStringContainsString('Templates (3)', $display);
        $this->assertStringContainsString('• Retail Demo', $display);
        $this->assertStringContainsString('Category: commerce', $display);
        $this->assertStringContainsString('Tags: retail, multilingual', $display);
        $this->assertStringNotContainsString('+---', $display);
    }

    #[Test]
    public function find_combines_metadata_filters(): void
    {
        $tester = new CommandTester(new TemplateFindCommand());

        $this->assertSame(0, $tester->execute([
            'directory' => self::DIRECTORY,
            '--tag' => ['multilingual', 'retail'],
            '--category' => 'COMMERCE',
            '--json' => true,
        ]));
        $result = $this->json($tester);

        $this->assertIsArray($result['templates']);
        $this->assertCount(1, $result['templates']);
        $this->assertIsArray($result['templates'][0]);
        $this->assertSame('Retail Demo', $result['templates'][0]['name']);
    }

    #[Test]
    public function show_outputs_merged_template_details(): void
    {
        $tester = new CommandTester(new TemplateShowCommand());

        $this->assertSame(0, $tester->execute([
            'file' => self::DIRECTORY . '/retail-demo.yaml',
            '--json' => true,
        ]));
        $result = $this->json($tester);

        $this->assertSame('Retail Demo', $result['name']);
        $this->assertSame('solutions-engineering', $result['owner']);
        $this->assertSame(['customer_name'], $result['inputs']);
        $this->assertSame('duplicate', $result['target_mode']);
        $this->assertSame(['base.yaml'], $result['extends']);
        $this->assertTrue($result['valid']);
    }

    /** @return array<mixed> */
    private function json(CommandTester $tester): array
    {
        $result = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);

        return $result;
    }
}
