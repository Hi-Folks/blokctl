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

        $roleNames = [];
        foreach (is_array($config['roles'] ?? null) ? $config['roles'] : [] as $role) {
            $name = is_array($role) && is_string($role['name'] ?? null) ? $role['name'] : null;
            if ($name !== null && in_array($name, $roleNames, true)) {
                $errors[] = '$.roles: Duplicate space role name "' . $name . '".';
            }

            $roleNames[] = $name;
        }

        $stories = is_array($config['stories'] ?? null) ? $config['stories'] : [];
        $publish = is_array($stories['publish'] ?? null) ? $stories['publish'] : [];
        $unpublish = is_array($stories['unpublish'] ?? null) ? $stories['unpublish'] : [];
        foreach (['slugs', 'ids', 'folders'] as $kind) {
            $both = array_intersect(
                array_map(strval(...), array_filter(is_array($publish[$kind] ?? null) ? $publish[$kind] : [], is_scalar(...))),
                array_map(strval(...), array_filter(is_array($unpublish[$kind] ?? null) ? $unpublish[$kind] : [], is_scalar(...))),
            );
            foreach (array_unique($both) as $value) {
                $errors[] = '$.stories.unpublish.' . $kind . ': "' . $value . '" is also listed under stories.publish.';
            }
        }

        return [...$errors, ...$this->workflowErrors($config)];
    }

    /**
     * Rules the schema cannot express for workflow.ensure: unique workflow names, a content
     * type in one workflow only, unique stage names and colors, one default stage, and
     * stage links that point to stages declared in the same workflow.
     *
     * @param array<mixed> $config
     * @return list<string>
     */
    private function workflowErrors(array $config): array
    {
        $workflow = is_array($config['workflow'] ?? null) ? $config['workflow'] : [];
        $errors = [];
        $workflowNames = [];
        $contentTypeOwners = [];

        foreach (is_array($workflow['ensure'] ?? null) ? $workflow['ensure'] : [] as $index => $definition) {
            if (!is_array($definition) || !is_string($definition['name'] ?? null)) {
                continue;
            }

            $name = $definition['name'];
            $path = '$.workflow.ensure[' . $index . ']';
            if (in_array($name, $workflowNames, true)) {
                $errors[] = $path . '.name: Duplicate workflow name "' . $name . '".';
            }

            $workflowNames[] = $name;

            foreach (is_array($definition['content_types'] ?? null) ? $definition['content_types'] : [] as $contentType) {
                if (!is_string($contentType)) {
                    continue;
                }

                if (isset($contentTypeOwners[$contentType]) && $contentTypeOwners[$contentType] !== $name) {
                    $errors[] = $path . '.content_types: Content type "' . $contentType . '" is already used by workflow "' . $contentTypeOwners[$contentType] . '". A content type can belong to only one workflow.';
                }

                $contentTypeOwners[$contentType] ??= $name;
            }

            $stages = array_values(array_filter(is_array($definition['stages'] ?? null) ? $definition['stages'] : [], is_array(...)));
            $stageNames = array_values(array_filter(array_map(static fn(array $stage): mixed => $stage['name'] ?? null, $stages), is_string(...)));
            $colors = array_values(array_filter(array_map(static fn(array $stage): mixed => $stage['color'] ?? null, $stages), is_string(...)));

            foreach (array_unique(array_diff_assoc($stageNames, array_unique($stageNames))) as $duplicate) {
                $errors[] = $path . '.stages: Duplicate stage name "' . $duplicate . '".';
            }

            foreach (array_unique(array_diff_assoc(array_map(strtolower(...), $colors), array_unique(array_map(strtolower(...), $colors)))) as $duplicate) {
                $errors[] = $path . '.stages: Duplicate stage color "' . $duplicate . '". Two stages in the same workflow cannot have the same color.';
            }

            if (count(array_filter($stages, static fn(array $stage): bool => ($stage['default'] ?? false) === true)) > 1) {
                $errors[] = $path . '.stages: Only one stage per workflow can be the default.';
            }

            foreach ($stages as $stageIndex => $stage) {
                $references = $stage['next'] ?? [];
                $references = is_array($references) ? $references : [];
                if (is_string($stage['after_publish'] ?? null)) {
                    $references[] = $stage['after_publish'];
                }

                foreach ($references as $reference) {
                    if (is_string($reference) && !in_array($reference, $stageNames, true)) {
                        $errors[] = $path . '.stages[' . $stageIndex . ']: Stage "' . $reference . '" is not declared in workflow "' . $name . '".';
                    }
                }
            }
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
