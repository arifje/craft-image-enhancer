<?php

namespace arjanbrinkman\craftimageenhancer\mcp;

use arifje\mcptools\enums\Capability;
use arifje\mcptools\http\Scope;
use arifje\mcptools\Mcp;
use arifje\mcptools\support\CallContext;
use arifje\mcptools\support\Caller;
use arjanbrinkman\craftimageenhancer\exceptions\EnhancementException as RuntimeException;
use arjanbrinkman\craftimageenhancer\ImageEnhancer;
use Craft;
use craft\elements\User;

/** HTTP principal and queued authorization, loaded only by optional MCP integration. */
class Access
{
    /**
     * The Craft user the call acts as: the user of an HTTP token, or the
     * signed-in user of a call made inside Craft (MCP Tools' LocalClient, as
     * used by the AI assistant). Never an implicit stdio/admin identity.
     */
    public static function user(): User
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user instanceof User || !Caller::current() instanceof Caller) {
            throw new RuntimeException('Connect as a Craft user: over HTTP with a user-bound Craft MCP Tools token, or from inside Craft (such as the AI assistant). A stdio connection has no user.');
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

    /**
     * Who started the request, to store with it: ids and a scope only, never
     * a token secret or hash.
     *
     * @return array<string, mixed>
     */
    public static function caller(): array
    {
        $caller = Caller::current();
        if (!$caller instanceof Caller || CallContext::scope() === Scope::ReadOnly || !CallContext::hasCapability(Capability::Upload)) {
            throw new RuntimeException('This operation needs a content/full connection with the upload capability.');
        }
        return $caller->toArray();
    }

    /**
     * Live caller, capability, section and plugin gates before paid work and
     * attachment.
     *
     * @param array<string, mixed>|int $caller the stored caller, or the token id requests stored before 3.6.0
     */
    public static function queued(array|int $caller, User $user, ?string $sectionUid): User
    {
        if (!Craft::$app->getPlugins()->isPluginEnabled('mcp-tools') || !Mcp::isToolEnabled('image_enhance')) {
            throw new RuntimeException('Image enhancement MCP tools have been disabled.');
        }
        $principal = is_int($caller) ? Caller::fromToken($caller, (int) $user->id) : Caller::fromArray($caller);
        $refusal = !$principal instanceof Caller || $principal->userId !== (int) $user->id
            ? Caller::NO_CALLER
            : $principal->refusal($sectionUid, 'image_enhance', [Capability::Upload]);
        if ($refusal !== null) {
            throw new RuntimeException($refusal . ' Submit a new authorized request.');
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
