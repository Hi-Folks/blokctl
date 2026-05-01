<?php

declare(strict_types=1);

namespace Blokctl\Action\Folder;

use Storyblok\ManagementApi\Data\Space;
use Storyblok\ManagementApi\Endpoints\SpaceApi;
use Storyblok\ManagementApi\ManagementApiClient;

final readonly class FolderDimensionAddAction
{
    public function __construct(
        private ManagementApiClient $client,
    ) {}

    /**
     * @throws \RuntimeException
     */
    public function execute(
        string $spaceId,
        string $folderName,
        string $aiTranslationCode = '',
    ): FolderDimensionAddResult {
        $spaceApi = new SpaceApi($this->client);

        $spaceData = $spaceApi->get($spaceId)->data()->toArray();

        /** @var int[] $folderIds */
        $folderIds = $spaceData['dimensions_app_folder_ids'] ?? [];
        /** @var array<int, array{folder_id: int, ai_translation_code: string}> $dimensionsFolders */
        $dimensionsFolders = $spaceData['dimensions_app_folders'] ?? [];

        $createAction = new FolderCreateAction($this->client);
        $createResult = $createAction->execute($spaceId, $folderName, parentId: 0);
        $newFolderId = (int) $createResult->folder->id();

        $folderIds[] = $newFolderId;
        $dimensionsFolders[] = [
            'folder_id'           => $newFolderId,
            'ai_translation_code' => $aiTranslationCode,
        ];

        $space = Space::forUpdate([
            'dimensions_app_folder_ids' => $folderIds,
            'dimensions_app_folders'    => $dimensionsFolders,
        ]);

        $response = $spaceApi->update($spaceId, $space);

        if (!$response->isOk()) {
            throw new \RuntimeException(
                'Failed to update dimensions app configuration: ' . $response->getErrorMessage(),
            );
        }

        return new FolderDimensionAddResult(
            folder: $createResult->folder,
            folderIds: $folderIds,
            dimensionsFolders: $dimensionsFolders,
        );
    }
}
