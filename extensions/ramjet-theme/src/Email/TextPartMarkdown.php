<?php

declare(strict_types=1);

namespace Ramjet\Theme\Email;

use Illuminate\Mail\Markdown;
use Illuminate\Support\HtmlString;

/**
 * Renders a notification's text part from the extension's plain-text template.
 *
 * Laravel's MailChannel renders a MailMessage's text part with the same view name as its HTML part
 * (`notifications::email`), so core's text part is its HTML template with the tags stripped. Core's
 * `email-plain` template is never referenced. This subclass maps the HTML view name to the plain one
 * for text rendering only; HTML rendering is unchanged.
 */
final class TextPartMarkdown extends Markdown
{
    /** @var array<string, string> HTML view name => plain-text view name */
    public const array TEXT_VIEWS = [
        'notifications::email' => 'notifications::email-plain',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function renderText($view, array $data = []): HtmlString
    {
        return parent::renderText(self::TEXT_VIEWS[$view] ?? $view, $data);
    }
}
