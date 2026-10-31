<?php

namespace arjanbrinkman\craftimageenhancer\jobs;

use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;

/** One durable enhancement request; unknown provider outcomes must never be replayed automatically. */
class McpEnhancementJob extends BaseJob implements RetryableJobInterface
{
    public string $requestId = '';

    /** @inheritdoc */
    public function execute($queue): void
    {
        ImageEnhancer::getInstance()->mcpEnhancements->process($this->requestId);
    }

    /** Provider timeout plus image storage and draft attachment. */
    public function getTtr(): int
    {
        return 900;
    }

    /** Manual retries recover durable checkpoints; transport failures are not automatically retried. */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    /** @inheritdoc */
    protected function defaultDescription(): ?string
    {
        return 'Enhance image for MCP request ' . $this->requestId;
    }
}
