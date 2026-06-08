<?php

declare(strict_types=1);

namespace Blokctl\SpaceSetup;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

final readonly class SpaceSetupConfigValidator
{
    public function __construct(
        private string $schemaPath = __DIR__ . '/../../space-setup-schema.json',
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public function validate(array $config): SpaceSetupConfigValidationResult
    {
        $schemaContent = file_get_contents($this->schemaPath);
        if ($schemaContent === false) {
            throw new \RuntimeException('Unable to read space setup schema: ' . $this->schemaPath);
        }

        $schema = json_decode($schemaContent, false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($schema)) {
            throw new \RuntimeException('Space setup schema must contain a JSON object.');
        }

        $data = json_decode(json_encode($config, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        $result = new Validator(null, 100, false)->validate($data, $schema);
        if ($result->isValid()) {
            return new SpaceSetupConfigValidationResult($this->semanticErrors($config));
        }

        $error = $result->error();
        if (!$error instanceof \Opis\JsonSchema\Errors\ValidationError) {
            return new SpaceSetupConfigValidationResult(['Configuration is invalid.']);
        }

        $formatted = new ErrorFormatter()->formatKeyed($error);
        $errors = [];

        foreach ($formatted as $pointer => $messages) {
            $path = $this->formatPath((string) $pointer);
            foreach ($messages as $message) {
                if (is_string($message)) {
                    $errors[] = $path . ': ' . $message;
                }
            }
        }

        return new SpaceSetupConfigValidationResult($errors);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return string[]
     */
    private function semanticErrors(array $config): array
    {
        $space = is_array($config['space'] ?? null) ? $config['space'] : [];
        $demoMode = is_array($config['demo_mode'] ?? null) ? $config['demo_mode'] : [];
        $errors = [];

        if (($space['demo'] ?? false) === true && ($demoMode['remove'] ?? false) === true) {
            $errors[] = '$.space.demo: Cannot mark a created space as demo when demo_mode.remove is true.';
        }

        if (($space['create_new'] ?? false) === true && array_key_exists('duplicate_from', $space)) {
            $errors[] = '$.space.create_new: Cannot combine space.create_new: true with space.duplicate_from.';
        }

        if (
            ($space['create_new'] ?? false) === true
            && (!is_string($space['name'] ?? null) || $space['name'] === '')
        ) {
            $errors[] = '$.space.name: space.name is required when using space.create_new: true.';
        }

        return $errors;
    }

    private function formatPath(string $pointer): string
    {
        if ($pointer === '' || $pointer === '/') {
            return '$';
        }

        $segments = explode('/', ltrim($pointer, '/'));
        $path = '$';

        foreach ($segments as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (ctype_digit($segment)) {
                $path .= '[' . $segment . ']';
            } else {
                $path .= '.' . $segment;
            }
        }

        return $path;
    }
}
