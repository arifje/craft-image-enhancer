<?php

namespace arjanbrinkman\craftimageenhancer\mcp;

use arifje\mcptools\enums\Capability;
use arifje\mcptools\http\RecordStore;
use arifje\mcptools\http\Scope;
use arifje\mcptools\Mcp;
use arifje\mcptools\records\Token as TokenRecord;
use arifje\mcptools\support\CallContext;
use arjanbrinkman\craftimageenhancer\exceptions\EnhancementException as RuntimeException;
use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use Craft;
use craft\elements\User;
use DateTimeImmutable;

/** HTTP principal and queued authorization, loaded only by optional MCP integration. */
class Access
{
    /** HTTP tools never borrow an implicit console/admin identity. */
    public static function user(): User
    {
        $user = Craft::$app->getUser()->getIdentity();
        $token = CallContext::token();
        if (!CallContext::isHttp() || !$token || !$user instanceof User || $token->userId !== (int) $user->id) {
            throw new RuntimeException('Connect over HTTP with a user-bound Craft MCP Tools token.');
        }
        self::assertUser($user);
        return $user;
    }

    /** Recheck the user independently of MCP full-scope bypasses. */
    public static function assertUser(User $user): void
    {
        if ($user->getStatus() !== User::STATUS_ACTIVE || !$user->can('accessCp') || !$user->can(ImageEnhancer::PERMISSION_USE_AI_TOOLS)) {
            throw new RuntimeException('The user needs access to the control panel and Use AI image tools.');
        }
    }

    /** Capture only a token identifier, never its secret or hash. */
    public static function tokenId(): int
    {
        $token = CallContext::token();
        if (!$token?->id || $token->scope === Scope::ReadOnly || !CallContext::hasCapability(Capability::Upload)) {
            throw new RuntimeException('This operation needs a content/full token with the upload capability.');
        }
        return $token->id;
    }

    /** Live token, capability, section and plugin gates before paid work and attachment. */
    public static function queued(int $tokenId, User $user, ?string $sectionUid): User
    {
        if (!Craft::$app->getPlugins()->isPluginEnabled('mcp-tools') || !Mcp::isToolEnabled('image_enhance')) {
            throw new RuntimeException('Image enhancement MCP tools have been disabled.');
        }
        $record = TokenRecord::findOne($tokenId);
        $token = $record ? (new RecordStore())->findByHash($record->tokenHash) : null;
        if (!$token || $token->userId !== (int) $user->id || $token->expired(new DateTimeImmutable()) || $token->scope === Scope::ReadOnly || !$token->hasCapability(Capability::Upload)
            || $token->scope->isDisabled(Mcp::getInstance()->getSettings()->disabledScopes)) {
            throw new RuntimeException('The initiating token was revoked, expired or no longer permits uploads. Submit a new authorized request.');
        }
        if ($sectionUid !== null && !$token->allowsSection($sectionUid)) {
            throw new RuntimeException('The initiating token no longer permits the draft section.');
        }
        // A provider call can outlive a permission change in another request.
        Craft::$app->getUserPermissions()->reset();
        $fresh = User::find()->id($user->id)->status(null)->one();
        if (!$fresh instanceof User) {
            throw new RuntimeException('The initiating user no longer exists.');
        }
        self::assertUser($fresh);
        Craft::$app->getUser()->setIdentity($fresh);
        return $fresh;
    }

    /** Current status/read requests also respect narrowed section tokens. */
    public static function section(?string $sectionUid): void
    {
        if ($sectionUid !== null && !CallContext::allowsSection($sectionUid)) {
            throw new RuntimeException('This draft is outside the token section scope.');
        }
    }
}
