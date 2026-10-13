<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck;

// import built-ins so calls resolve at compile time instead of per-call lookups; NamespacedCallsTest keeps this list exact
use function sprintf;

/**
 * One rule the uploaded SVG broke. Found in Result::$errors.
 *
 *     $violation->code;       // 'element-not-allowed'
 *     $violation->detail;     // 'foreignObject'
 *     $violation->template;   // '<%s> is not allowed in uploaded SVGs'
 *     $violation->message;    // '<foreignObject> is not allowed in uploaded SVGs'
 *
 * code is stable across releases, so callers can switch on it. template is the English
 * sentence with one %s where detail goes, and message is the two combined. To translate,
 * run the template through your translation function and sprintf() the detail back in:
 *
 *     echo htmlspecialchars(sprintf(t($violation->template), $violation->detail));
 *
 * TEMPLATES lists every template by code, so a translation system can register all of
 * them up front. detail comes from the uploaded file: always one line of valid UTF-8 (control
 * characters and invalid bytes are escaped, as \n or \351), but it can hold </script>, quotes
 * and backticks, so encode it for wherever it goes (HTML-encode for a page, json_encode()
 * with the JSON_HEX_* flags for a <script> block).
 */
final class Violation
{
    public const TEMPLATES = [
        'file-unreadable'               => 'Cannot read file %s',
        'not-svg'                       => 'This is not an SVG file: it starts with %s',
        'not-utf8'                      => 'SVG files must be UTF-8, this one is %s',
        'malformed-xml'                 => 'The SVG is not well-formed XML: %s',
        'doctype-not-allowed'           => 'The DOCTYPE declaration is not allowed because %s',
        'processing-instruction'        => 'Processing instructions like <?%s?> are not allowed',
        'comment-not-allowed'           => 'A comment starting with <!--%s is not allowed, HTML parsers close it there',
        'cdata-not-allowed'             => 'A CDATA section containing > is not allowed inside <%s>, HTML parsers end it there',
        'root-not-svg'                  => 'The root element must be <svg>, not <%s>',
        'root-namespace-wrong'          => 'The root <svg> element must declare xmlns="http://www.w3.org/2000/svg", but it has %s',
        'element-not-allowed'           => '<%s> is not allowed in uploaded SVGs',
        'namespace-not-allowed'         => 'Elements from the XML namespace %s are not allowed',
        'element-prefix-wrong'          => '<%s> has the wrong prefix: SVG elements take none, metadata elements need one',
        'event-handler'                 => '%s= event handler attributes are not allowed',
        'attribute-not-allowed'         => 'The %s attribute is not allowed',
        'xlink-prefix-wrong'            => 'The xlink: prefix must be bound to http://www.w3.org/1999/xlink, not %s',
        'href-not-allowed'              => 'href must reference an element in the same file (#id), not %s',
        'image-href-not-allowed'        => 'Image href must be #id or an embedded PNG, JPEG, GIF, WebP or SVG data: URL, not %s',
        'embedded-svg-not-allowed'      => 'An embedded SVG image was rejected: %s',
        'url-not-fragment'              => 'url() in the %s attribute must reference an element in the same file (#id)',
        'reference-expansion-too-large' => 'The references in this file %s',
        'css-not-allowed'               => 'CSS containing %s is not allowed',
        'animation-target-not-allowed'  => 'Animating the %s attribute is not allowed',
    ];

    public readonly string $template;
    public readonly string $message;

    public function __construct(
        public readonly string $code,
        public readonly string $detail,
    ) {
        $this->template = self::TEMPLATES[$code];
        $this->message  = sprintf($this->template, $detail);
    }
}
