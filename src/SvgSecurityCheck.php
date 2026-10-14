<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck;

use Closure;
use LibXMLError;
use XMLReader;

// import built-ins so calls resolve at compile time instead of per-call lookups; NamespacedCallsTest keeps this list exact
use function addcslashes, array_key_last, array_keys, array_map, array_pop, array_push, array_values, base64_decode, basename, count, explode, file_get_contents, implode, in_array, intdiv, is_file, is_readable, libxml_clear_errors, libxml_get_errors, libxml_use_internal_errors, min, number_format, preg_match, preg_match_all, rawurldecode, rawurlencode, rtrim, str_contains, str_replace, str_starts_with, strcasecmp, stripos, strlen, strpos, strrchr, strspn, strtolower, strval, substr, substr_compare, trim;
use const LIBXML_NONET, PHP_OS_FAMILY;

/**
 * Checks an uploaded SVG file and rejects it if it could run script, load an outside
 * resource, or hang a renderer. Browsers block the same things for an SVG in an <img> tag,
 * but not when the file is opened on its own; this checks the file, so it is safe either
 * way. The file is streamed and never modified.
 *
 *     $result = SvgSecurityCheck::checkFile($_FILES['logo']['tmp_name']);
 *     if (!$result->ok) {
 *         foreach ($result->errors as $violation) {
 *             echo htmlspecialchars($violation->message), "<br>";
 *         }
 *     }
 *
 *     $result = SvgSecurityCheck::checkString($svg);   // same check on a string
 *     $rules  = SvgSecurityCheck::rules();             // the allowlists, for docs and debugging
 *
 * Elements, attributes, XML namespaces, URL forms, animation targets, CSS functions and CSS
 * at-rules not on a list are rejected, so a new browser feature is closed until it is added
 * here. On top of the lists, on* attributes, script in any namespace and three CSS tokens
 * (a backslash, -moz-binding, behavior:) are banned by name. docs/errors.md has the fix for
 * each code and docs/ai-reference.md every rule and list.
 */
final class SvgSecurityCheck
{
    //region Allowlists

    private const SVG_NS   = 'http://www.w3.org/2000/svg';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';
    private const XML_NS   = 'http://www.w3.org/XML/1998/namespace';
    private const XMLNS_NS = 'http://www.w3.org/2000/xmlns/';

    /**
     * Elements allowed in the SVG namespace. Not here on purpose: script, foreignObject, handler, listener, iframe, embed,
     * font (and the other SVG font elements), tref, cursor, animateColor, discard, and marker, whose content is drawn again
     * at every point of a line, so a 70 KB file can keep Chrome busy for minutes.
     */
    private const ELEMENTS = [
        // structure
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata', 'switch', 'a', 'view', 'style',

        // shapes, text, images
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'textPath', 'image',

        // paint servers, clipping, masking
        'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask',

        // filters
        'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite', 'feConvolveMatrix', 'feDiffuseLighting',
        'feDisplacementMap', 'feDistantLight', 'feDropShadow', 'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR',
        'feGaussianBlur', 'feImage', 'feMerge', 'feMergeNode', 'feMorphology', 'feOffset', 'fePointLight', 'feSpecularLighting',
        'feSpotLight', 'feTile', 'feTurbulence',

        // animation
        'animate', 'set', 'animateTransform', 'animateMotion', 'mpath',
    ];

    /** Attributes allowed without a prefix, plus data-* and aria-*. Not here on purpose: on*, cursor, tabindex, target, crossorigin. */
    private const ATTRIBUTES = [
        // core, conditional processing, accessibility
        'id', 'class', 'style', 'lang', 'role', 'requiredExtensions', 'requiredFeatures', 'systemLanguage', 'externalResourcesRequired',

        // root and viewport
        'version', 'baseProfile', 'viewBox', 'preserveAspectRatio', 'zoomAndPan', 'contentStyleType', 'x', 'y', 'width', 'height',

        // geometry and references
        'cx', 'cy', 'r', 'rx', 'ry', 'x1', 'y1', 'x2', 'y2', 'fx', 'fy', 'fr', 'd', 'points', 'pathLength', 'href', 'transform',
        'transform-origin', 'transform-box',

        // text
        'dx', 'dy', 'rotate', 'textLength', 'lengthAdjust', 'startOffset', 'method', 'spacing', 'side',

        // gradients, patterns, clipping, masking, symbols
        'gradientUnits', 'gradientTransform', 'spreadMethod', 'offset', 'patternUnits', 'patternContentUnits', 'patternTransform',
        'clipPathUnits', 'maskUnits', 'maskContentUnits', 'refX', 'refY',

        // filters
        'filterUnits', 'primitiveUnits', 'filterRes', 'in', 'in2', 'result', 'mode', 'type', 'values', 'tableValues', 'slope',
        'intercept', 'amplitude', 'exponent', 'k1', 'k2', 'k3', 'k4', 'operator', 'radius', 'stdDeviation', 'edgeMode',
        'kernelMatrix', 'order', 'divisor', 'bias', 'targetX', 'targetY', 'kernelUnitLength', 'preserveAlpha', 'surfaceScale',
        'diffuseConstant', 'specularConstant', 'specularExponent', 'z', 'azimuth', 'elevation', 'pointsAtX', 'pointsAtY', 'pointsAtZ',
        'limitingConeAngle', 'xChannelSelector', 'yChannelSelector', 'scale', 'baseFrequency', 'numOctaves', 'seed', 'stitchTiles',

        // animation
        'attributeName', 'attributeType', 'begin', 'dur', 'end', 'min', 'max', 'restart', 'repeatCount', 'repeatDur', 'calcMode',
        'keyTimes', 'keySplines', 'from', 'to', 'by', 'additive', 'accumulate', 'path', 'keyPoints', 'origin',

        // presentation
        'alignment-baseline', 'baseline-shift', 'clip', 'clip-path', 'clip-rule', 'color', 'color-interpolation',
        'color-interpolation-filters', 'color-rendering', 'direction', 'display', 'dominant-baseline', 'enable-background', 'fill',
        'fill-opacity', 'fill-rule', 'filter', 'flood-color', 'flood-opacity', 'font', 'font-family', 'font-kerning', 'font-size',
        'font-size-adjust', 'font-stretch', 'font-style', 'font-variant', 'font-weight', 'glyph-orientation-horizontal',
        'glyph-orientation-vertical', 'image-rendering', 'isolation', 'kerning', 'letter-spacing', 'lighting-color', 'marker',
        'marker-end', 'marker-mid', 'marker-start', 'mask', 'mask-type', 'mix-blend-mode', 'opacity', 'overflow', 'paint-order',
        'pointer-events', 'shape-rendering', 'stop-color', 'stop-opacity', 'stroke', 'stroke-dasharray', 'stroke-dashoffset',
        'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-opacity', 'stroke-width', 'text-anchor', 'text-decoration',
        'text-rendering', 'unicode-bidi', 'vector-effect', 'visibility', 'white-space', 'word-spacing', 'writing-mode',
    ];

    /**
     * Prefixed attributes allowed, by namespace. Not here on purpose: xml:base, xlink:actuate,
     * xlink:show, and xml:id, which browsers ignore but Batik resolves as an id; allowing it would
     * give a reference bomb ids the expansion check does not count.
     */
    private const NAMESPACED_ATTRIBUTES = [
        self::XML_NS   => ['lang', 'space'],
        self::XLINK_NS => ['href', 'title'],
    ];

    /**
     * Namespaces browsers ignore: design-tool and metadata vocabularies. Element and attribute
     * names in them pass without a name check, but on* attributes and script still reject, the
     * element must be written with a prefix, and the unprefixed attributes of an inert element
     * get the href, CSS and url() checks. Attributes in these namespaces skip the value checks:
     * they always have a prefix, and it is never xlink, so no parser reads them. Matched by
     * prefix, since Adobe alone uses dozens of namespaces under one host.
     * @noinspection HttpUrlsUsage
     */
    private const INERT_NAMESPACES = [
        'http://www.inkscape.org/namespaces/inkscape',
        'http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd',
        'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'http://www.w3.org/2000/01/rdf-schema#',
        'http://purl.org/dc/',
        'http://creativecommons.org/ns#',
        'http://web.resource.org/cc/',
        'http://ns.adobe.com/',
        'adobe:ns:meta/',
        'http://www.serif.com/',
        'http://www.bohemiancoding.com/sketch/ns',
        'http://schemas.microsoft.com/visio/2003/SVGExtensions/',
        'http://www.w3.org/2001/XMLSchema-instance',
    ];

    /**
     * Processing instructions browsers ignore, by target. xpacket marks the start and end of
     * the XMP metadata block Adobe tools write; nothing reads the marker. Every other target
     * rejects, since xml-stylesheet attaches CSS.
     */
    private const INERT_PROCESSING_INSTRUCTIONS = ['xpacket'];

    /** The only elements whose href may be an embedded data: image. Every other href must be #id. */
    private const IMAGE_ELEMENTS = ['image', 'feImage'];

    private const ANIMATION_ELEMENTS         = ['animate', 'set', 'animateTransform', 'animateMotion'];
    private const ANIMATION_TARGETS_EXCLUDED = ['href', 'style', 'class', 'id'];   // allowed in the file but not as targets: they change URLs, CSS and references after the check

    /**
     * CSS functions allowed in <style>, style= and every other attribute value except href,
     * data-* and aria-*, lowercase. Outside
     * resources load through functions (url(), image-set()), so a function a browser adds later
     * is rejected until it is listed here. url() targets are checked on their own.
     */
    private const CSS_FUNCTIONS = [
        'url', 'format',                                                                          // references, @font-face
        'matrix', 'translate', 'translatex', 'translatey', 'scale', 'scalex', 'scaley', 'rotate', 'skew', 'skewx', 'skewy',
        'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch', 'color', 'color-mix', 'light-dark', 'icc-color',   // colors
        'blur', 'brightness', 'contrast', 'drop-shadow', 'grayscale', 'hue-rotate', 'invert', 'opacity', 'saturate', 'sepia',
        'circle', 'ellipse', 'inset', 'polygon', 'path', 'rect',                                  // shapes, for clip-path and clip
        'calc', 'var', 'min', 'max', 'clamp',                                                     // math and custom properties
        'cubic-bezier', 'steps',                                                                  // animation timing
        'not', 'is', 'where', 'has', 'nth-child', 'nth-last-child', 'nth-of-type', 'nth-last-of-type', 'lang', 'dir',   // selectors
    ];

    /** CSS at-rules allowed in <style> and style=, lowercase and without the @. */
    private const CSS_AT_RULES = ['font-face', 'keyframes', '-webkit-keyframes', 'media', 'supports', 'charset'];

    //endregion
    //region Patterns and Limits

    // data:image/png;base64, and the other embedded image forms allowed on <image> and <feImage>; jpg is a common mislabel browsers accept
    private const DATA_IMAGE = '/^data:image\/(png|jpeg|jpg|gif|webp|svg\+xml);base64,/i';

    // url( not followed by #, allowing whitespace and a quote first: url(https://x), url( "x" ). Possessive
    // quantifiers (*+ ?+) stop the regex from backtracking past the quote to dodge the # lookahead.
    private const URL_NOT_FRAGMENT = '/url\(\s*+["\']?+\s*+(?!#)/i';

    // the css-not-allowed detail when a CSS regex returns false past a PCRE limit, which rejects like a match
    private const UNSCANNABLE = 'text that could not be scanned';

    // CSS the allowlists cannot catch: a backslash (an escape could spell any name) and the two properties
    // old Firefox and IE used to attach code, whose value is an allowed url(#id). behavior must be the whole
    // property name, so scroll-behavior passes and IE's _behavior, *behavior and -ms-behavior still reject.
    private const CSS_BANNED = '/\\\\|-moz-binding|(?<![a-z0-9-])(?:-ms-)?behavior\s*+:/i';

    // a CSS function name and its (: rgb(, image-set(, --custom(. The lookbehind makes it a whole name, so in
    // x-rotate( the name is x-rotate, and a name after @ is an at-rule, so minified @media( is not a function.
    // Possessive, so a long name costs one pass.
    private const CSS_FUNCTION = '/(?<![\w@-])(-{0,2}+[a-z_][\w-]*+)\(/i';

    // a CSS at-rule name: @media, @import
    private const CSS_AT_RULE = '/@(-?+[a-z_][\w-]*+)/i';

    // url( in CSS that is not #id and not an embedded font (data:font/... or data:;base64,...), with up to 40 chars of context
    // (u so the 40 counts characters, not bytes: a cut inside a multibyte character would make detail invalid UTF-8).
    // The whitespace is CSS's own, not \s: with u, \s also skips a no-break space, which a browser keeps as part of the URL.
    private const CSS_URL_NOT_ALLOWED = '/url\([ \t\n\r\f]*+["\']?+[ \t\n\r\f]*+(?!#|data:font\/|data:;base64,)[^)]{0,40}/iu';

    // Limits. Public so an application with an unusual file can raise one; the memory a hostile file can cost rises with it.
    public static int $prologLimit         = 65536;    // the root tag must start within this many bytes
    public static int $maxErrors           = 50;       // distinct errors reported per file
    public static int $maxEmbedDepth       = 3;        // SVG inside SVG inside SVG, then stop
    public static int $maxStyleLength      = 1000000;  // bytes of text in one <style> element, held whole until its closing tag
    public static int $maxExpandedElements = 100000;   // elements the references in a file may add up to when expanded
    public static int $maxReferenceEntries = 100000;   // ids and (id, target) pairs the expansion check may hold; the same target inside the same id is one entry
    public static int $maxDetailLength     = 60;       // characters of a value quoted in an error message before "..."

    //endregion
    //region Public API

    /**
     * Checks an SVG file on disk. Streams it, so memory use does not depend on file size.
     * A path that is not a readable file gives a result with one file-unreadable error;
     * nothing throws.
     *
     *     $result = SvgSecurityCheck::checkFile('/tmp/php3F.tmp');
     *
     * @param string $path Path to the file, such as an upload's tmp_name
     */
    public static function checkFile(string $path): Result
    {
        $firstBytes = is_file($path) && is_readable($path) ? file_get_contents($path, false, null, 0, self::$prologLimit) : false;
        if ($firstBytes === false) {
            return new Result([new Violation('file-unreadable', self::escapeAsOneLine(basename($path)))]);
        }
        // @ because a failed open is reported in the Result, not as a PHP warning
        return (new self(0))->check($firstBytes, fn(XMLReader $reader) => @$reader->open(self::fileUri($path), null, LIBXML_NONET), basename($path));
    }

    /**
     * The path in the form XMLReader::open() needs. PHP's XML loaders (XMLReader::open(),
     * DOMDocument::load(), simplexml_load_file()) hand the string to libxml2, which takes "a
     * filename or URL" and decodes %XX in it as if it were a URL. Plain file functions such as
     * file_get_contents() do not. So a file named 50%_off.svg is looked up as 50_off.svg and
     * never found. Encoding each folder and file name first makes that decode give back the
     * real name. Windows skips the decode (C: reads as a URL scheme), so it gets the path as is.
     */
    private static function fileUri(string $path): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $path;
        }
        $segments = explode('/', $path);
        $encoded  = array_map(rawurlencode(...), $segments);
        return implode('/', $encoded);
    }

    /**
     * Checks SVG source held in a string. Same result as checkFile() for the same bytes.
     *
     *     $result = SvgSecurityCheck::checkString('<svg xmlns="http://www.w3.org/2000/svg"/>');
     */
    public static function checkString(string $svg): Result
    {
        return self::checkEmbedded($svg, 0);
    }

    /**
     * Returns the allowlists as arrays, keyed by what they list: elements, attributes,
     * namespacedAttributes, inertNamespaces, inertProcessingInstructions, imageElements,
     * dataImageTypes, cssFunctions, cssAtRules. For documentation and debugging; the rules
     * themselves are not configurable.
     *
     * @return array<string, array>
     */
    public static function rules(): array
    {
        return [
            'elements'                    => self::ELEMENTS,
            'attributes'                  => self::ATTRIBUTES,
            'namespacedAttributes'        => self::NAMESPACED_ATTRIBUTES,
            'inertNamespaces'             => self::INERT_NAMESPACES,
            'inertProcessingInstructions' => self::INERT_PROCESSING_INSTRUCTIONS,
            'imageElements'               => self::IMAGE_ELEMENTS,
            'dataImageTypes'              => ['png', 'jpeg', 'jpg', 'gif', 'webp', 'svg+xml'],
            'cssFunctions'                => self::CSS_FUNCTIONS,
            'cssAtRules'                  => self::CSS_AT_RULES,
        ];
    }

    //endregion
    //region Check Flow

    /** @var array<string, Violation> keyed by code and detail, so the same problem reports once */
    private array $errors = [];

    /** the first libxml diagnostic of this parse, saved before a nested parse clears the process-wide buffer */
    private ?LibXMLError $libxmlError = null;

    private function __construct(private readonly int $embedDepth)
    {
    }

    private static function checkEmbedded(string $svg, int $embedDepth): Result
    {
        return (new self($embedDepth))->check(substr($svg, 0, self::$prologLimit),fn(XMLReader $reader) => $reader->XML($svg, null, LIBXML_NONET), '');
    }

    /**
     * Runs the byte-level prolog check on the first $prologLimit bytes, then streams the document. $load
     * points the reader at the source and runs only when the prolog gave no reason to stop,
     * so the parser never sees bytes the prolog rejected. When it returns false (a file
     * deleted or locked since checkFile() found it readable; a string never fails to load)
     * the result is file-unreadable with $unreadableDetail as the detail.
     */
    private function check(string $firstBytes, Closure $load, string $unreadableDetail): Result
    {
        if ($this->prologAllowsParsing($firstBytes)) {
            $reader = new XMLReader();
            if ($load($reader)) {
                $this->walk($reader);
            } else {
                $this->fail('file-unreadable', $unreadableDetail);
            }
        }
        return new Result(array_values($this->errors));
    }

    private function fail(string $code, string $detail): void
    {
        if (count($this->errors) >= self::$maxErrors) {
            return;   // the read loop stops at the cap too, but one element can add several errors before it checks
        }
        $detail = self::escapeAsOneLine($detail);
        $this->errors["$code\0$detail"] ??= new Violation($code, $detail);
    }

    //endregion
    //region Prolog

    /**
     * Checks the bytes before the root element: BOM, encoding declaration, DOCTYPE. XMLReader
     * cannot report these in a usable form, and a DOCTYPE with an internal subset must be
     * refused before the parser expands anything it declares. Returns false when the file is
     * not worth parsing.
     */
    private function prologAllowsParsing(string $firstBytes): bool
    {
        if (str_starts_with($firstBytes, "\xEF\xBB\xBF")) {
            $firstBytes = substr($firstBytes, 3);
        }
        foreach (["\xFF\xFE", "\xFE\xFF", "<\0", "\0<", "\0\0"] as $utf16or32Start) {
            if (str_starts_with($firstBytes, $utf16or32Start)) {
                $this->fail('not-utf8', 'UTF-16 or UTF-32');
                return false;
            }
        }
        $hasEncoding = preg_match('/^<\?xml\s[^>]*?encoding\s*=\s*["\']([^"\']*)["\']/i', $firstBytes, $match);
        if ($hasEncoding === false) {   // a PCRE limit was reached, so the encoding is unknown
            $this->fail('not-utf8', 'declared in a form that could not be read');
            return false;
        }
        if ($hasEncoding === 1 && strcasecmp($match[1], 'utf-8') !== 0) {
            $this->fail('not-utf8', 'declared as ' . self::excerpt($match[1]));
            return false;
        }

        $pos = strspn($firstBytes, " \t\r\n");
        if (substr($firstBytes, $pos, 1) !== '<') {
            $start = addcslashes(substr($firstBytes, 0, 20), "\0..\37\177..\377");
            $this->fail('not-svg', trim($firstBytes) === '' ? 'nothing (the file is empty)' : $start);
            return false;
        }

        // walk the prolog: comments, PIs, and the DOCTYPE, until the root tag
        $length = strlen($firstBytes);
        while ($pos < $length) {
            $pos += strspn($firstBytes, " \t\r\n", $pos);
            if (substr_compare($firstBytes, '<!--', $pos, 4) === 0) {
                $end = strpos($firstBytes, '-->', $pos + 4);
                if ($end === false) {
                    break;
                }
                $pos = $end + 3;
            } elseif (substr_compare($firstBytes, '<?', $pos, 2) === 0) {
                $end = strpos($firstBytes, '?>', $pos + 2);
                if ($end === false) {
                    break;
                }
                $pos = $end + 2;
            } elseif (substr_compare($firstBytes, '<!DOCTYPE', $pos, 9) === 0) {
                $end = $this->doctypeEnd($firstBytes, $pos + 9);
                if ($end === null) {
                    return false;
                }
                $pos = $end;
            } else {
                return true;   // the root start tag, or something the parser will report
            }
        }
        $this->fail('malformed-xml', 'no root element within the first ' . intdiv(self::$prologLimit, 1024) . ' KB');
        return false;
    }

    /**
     * Returns the offset just past the DOCTYPE's closing >, or null after reporting an
     * internal subset or a quoted id that holds a >. XML skips quoted public and system ids,
     * so a [ inside them does not count. An HTML parser ends the DOCTYPE at the first >,
     * quotes or not, so markup after a > inside an id would run unchecked if the file were
     * served as text/html.
     */
    private function doctypeEnd(string $head, int $pos): ?int
    {
        $length = strlen($head);
        while ($pos < $length) {
            $char = $head[$pos];
            if ($char === '"' || $char === "'") {
                $close = strpos($head, $char, $pos + 1);
                if ($close === false) {
                    return $length;
                }
                $gt = strpos($head, '>', $pos + 1);
                if ($gt !== false && $gt < $close) {
                    $this->fail('doctype-not-allowed', 'a quoted id contains >, where HTML parsers end it');
                    return null;
                }
                $pos = $close + 1;
                continue;
            }
            if ($char === '[') {
                $this->fail('doctype-not-allowed', 'it contains an internal DTD subset (entity declarations)');
                return null;
            }
            if ($char === '>') {
                return $pos + 1;
            }
            $pos++;
        }
        return $length;
    }

    //endregion
    //region Document Walk

    private function walk(XMLReader $reader): void
    {
        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();
        // both default to false; set here because they are the whole defense against DTD entities
        $reader->setParserProperty(XMLReader::LOADDTD, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

        $isRoot     = true;
        $styleDepth = null;   // depth of the open <style> while its text is being collected
        $css        = '';
        $textDepth  = null;   // depth of the open <title> or <desc>, see the element and CDATA cases below
        $textName   = '';

        while (count($this->errors) < self::$maxErrors && $reader->read()) {
            switch ($reader->nodeType) {
                case XMLReader::ELEMENT:
                    if ($isRoot) {
                        $isRoot = false;
                        if ($reader->localName !== 'svg') {
                            $this->fail('root-not-svg', self::excerpt($reader->name));
                            break 2;
                        }
                        if ($reader->namespaceURI !== self::SVG_NS) {   // a hand-written <svg> with no xmlns: browsers show nothing
                            $this->fail('root-namespace-wrong', $reader->namespaceURI === '' ? 'none' : 'xmlns="' . self::excerpt($reader->namespaceURI) . '"');
                            break 2;
                        }
                    }
                    if ($textDepth !== null) {
                        // an HTML parser reads elements inside <title> and <desc> as HTML, where <style/> is not
                        // empty: the text after it would be CSS this check never saw, if the file were served as text/html
                        $this->fail('element-in-title-or-desc', self::excerpt($reader->name));
                    }
                    $this->checkElement($reader);
                    $this->noteReferences($reader);
                    if ($styleDepth === null && $reader->localName === 'style' && $reader->namespaceURI === self::SVG_NS && !$reader->isEmptyElement) {
                        $styleDepth = $reader->depth;
                    }
                    if ($textDepth === null && ($reader->localName === 'title' || $reader->localName === 'desc') && $reader->namespaceURI === self::SVG_NS && !$reader->isEmptyElement) {
                        $textDepth = $reader->depth;
                        $textName  = $reader->localName;
                    }
                    break;
                case XMLReader::END_ELEMENT:
                    $this->noteEndElementForReferences($reader->depth);
                    if ($reader->depth === $styleDepth) {
                        $this->checkCss($css);
                        $styleDepth = null;
                        $css        = '';
                    }
                    if ($reader->depth === $textDepth) {
                        $textDepth = null;
                    }
                    break;
                case XMLReader::COMMENT:
                    // <!--> and <!---> are complete comments to an HTML parser, so what follows them
                    // would be live markup if the file were ever served as text/html
                    if (str_starts_with($reader->value, '>') || str_starts_with($reader->value, '->')) {
                        $this->fail('comment-not-allowed', str_starts_with($reader->value, '>') ? '>' : '->');
                    }
                    break;
                case XMLReader::CDATA:
                    // inside <title> and <desc> an HTML parser is back on HTML rules, where <![CDATA[ is a
                    // bogus comment that ends at the first >, so the rest would be live markup if the file
                    // were ever served as text/html (<foreignObject>, the third such element, is banned)
                    if ($textDepth !== null && str_contains($reader->value, '>')) {
                        $this->fail('cdata-not-allowed', $textName);
                    }
                    // no break: CDATA is collected as text below
                case XMLReader::TEXT:
                case XMLReader::WHITESPACE:
                case XMLReader::SIGNIFICANT_WHITESPACE:
                    if ($styleDepth === null) {
                        break;
                    }
                    if (strlen($css) + strlen($reader->value) > self::$maxStyleLength) {
                        $this->fail('css-not-allowed', 'more than ' . number_format(self::$maxStyleLength) . ' bytes');
                        $styleDepth = null;   // the rest of this <style> is not collected
                        $css        = '';
                    } else {
                        $css .= $reader->value;   // browsers use the element's whole text content, child elements included
                    }
                    break;
                case XMLReader::PI:
                    if (!in_array($reader->name, self::INERT_PROCESSING_INSTRUCTIONS, true)) {
                        $this->fail('processing-instruction', self::excerpt($reader->name));
                    } elseif (str_contains($reader->value, '>')) {
                        // an HTML parser ends <?...> at the first >, so the rest would be live markup if the
                        // file were ever served as text/html
                        $this->fail('processing-instruction', self::excerpt("$reader->name $reader->value"));
                    }
                    break;
            }
        }

        // any libxml diagnostic counts, not only fatal ones: browsers render up to the first error,
        // and a file that parses differently in two parsers is where trouble starts
        $libxmlError = $this->libxmlError ?? libxml_get_errors()[0] ?? null;
        if ($libxmlError !== null) {
            $this->fail('malformed-xml', self::excerpt(trim($libxmlError->message)) . " (line $libxmlError->line)");
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);
        $reader->close();

        if ($libxmlError === null) {
            $this->checkReferenceExpansion();
        }
    }

    private function checkElement(XMLReader $reader): void
    {
        $namespace = $reader->namespaceURI;
        $element   = $reader->localName;
        $inert     = $namespace !== self::SVG_NS;   // past the checks below, every element not in SVG is in an inert namespace

        if (strcasecmp($element, 'script') === 0) {   // any namespace: <x:script> runs nothing, but "no script anywhere" is a simpler rule to rely on
            $this->fail('element-not-allowed', self::excerpt($reader->name));
            return;
        }
        if ($namespace === self::SVG_NS) {
            if (!in_array($element, self::ELEMENTS, true)) {
                $this->fail('element-not-allowed', self::excerpt($element));
                return;   // one error for the element; its attributes are not worth reporting
            }
        } elseif ($namespace === '') {
            $this->fail('element-not-allowed', self::excerpt($reader->name));   // unbound prefix, or xmlns="" reset
            return;
        } elseif (!self::isInertNamespace($namespace)) {
            $this->fail('namespace-not-allowed', self::excerpt($namespace));
            return;
        }
        // an HTML parser ignores xmlns and reads an element by its name as written, so <svg:svg> is not SVG
        // to it and <p xmlns="http://ns.adobe.com/x"> is an HTML <p>
        if (($reader->prefix === '') !== ($namespace === self::SVG_NS)) {
            $this->fail('element-prefix-wrong', self::excerpt($reader->name));
            return;
        }

        if (!$reader->moveToFirstAttribute()) {
            return;
        }
        do {
            $this->checkAttribute($reader, $element, $inert);
        } while ($reader->moveToNextAttribute());
        $reader->moveToElement();
    }

    private function checkAttribute(XMLReader $reader, string $element, bool $inertElement): void
    {
        $namespace = $reader->namespaceURI;
        $attribute = $reader->localName;
        $name      = $reader->name;     // as written, such as xlink:href
        $value     = $reader->value;

        if ($namespace === self::XMLNS_NS) {
            // an HTML parser lowercases attribute names and reads xlink:href by its spelling, so xmlns:xlink or
            // xmlns:XLINK="http://ns.adobe.com/x" would turn an unchecked metadata attribute into a working link
            // if the file were served as text/html
            if (strcasecmp($attribute, 'xlink') === 0 && $value !== self::XLINK_NS) {
                $this->fail('xlink-prefix-wrong', self::excerpt($value));
            }
            return;
        }
        if (stripos($attribute, 'on') === 0) {
            $this->fail('event-handler', self::excerpt($name));
            return;
        }
        if ($namespace !== '' && self::isInertNamespace($namespace)) {   // always prefixed, and the prefix is never xlink in any case, so no parser reads it
            return;
        }

        // a metadata element's own attributes are on no list, but their values get the checks below:
        // served as text/html it is an unknown SVG element, and Chrome applies its style
        if (!$inertElement) {
            $allowed = $namespace === ''
                ? in_array($attribute, self::ATTRIBUTES, true) || str_starts_with($attribute, 'data-') || str_starts_with($attribute, 'aria-')
                : in_array($attribute, self::NAMESPACED_ATTRIBUTES[$namespace] ?? [], true);
            if (!$allowed) {
                $this->fail('attribute-not-allowed', self::excerpt($name));
                return;
            }
        }

        if ($attribute === 'href') {
            $this->checkHref($element, $value);
        } elseif ($attribute === 'style') {
            $this->checkCss($value);
        } else {
            // presentation attributes are CSS values: u\72l( reads as url( to a browser, and mask="image-set(...)" loads the image
            $isCssValue = !str_starts_with($attribute, 'data-') && !str_starts_with($attribute, 'aria-');
            if ($isCssValue) {
                if (preg_match(self::CSS_BANNED, $value, $match) !== 0) {   // false (a PCRE limit) rejects too
                    $this->fail('css-not-allowed', self::excerpt($match[0] ?? self::UNSCANNABLE));
                }
                if (str_contains($value, '(')) {   // no ( means no function, and most values (paths, colors, numbers) have none
                    $this->checkCssNames($value, self::CSS_FUNCTION, self::CSS_FUNCTIONS);
                }
            }
            if (preg_match(self::URL_NOT_FRAGMENT, $value) !== 0) {
                $this->fail('url-not-fragment', self::excerpt($name));   // fill="url(https://...)" and friends
            }
            if ($attribute === 'attributeName' && in_array($element, self::ANIMATION_ELEMENTS, true)) {
                $this->checkAnimationTarget($value);
            }
        }
    }

    //endregion
    //region Reference Expansion

    /*
     * A <use> renders a copy of the element it references, and that copy can hold more <use>
     * elements. A few dozen elements arranged as a tree of references expand to billions of
     * rendered elements, which hangs a browser tab or a server-side rasterizer. Patterns,
     * masks, filters and gradients are references too: fill="url(#p)" renders the
     * pattern's content, and that content can be filled with the next pattern. Loops are caught
     * the same way. The walk records, for every element with an id, how many elements it
     * contains and which ids the elements inside it reference; the count runs once the whole
     * file has been read, since a target may be defined after the reference.
     */

    // elements whose href renders or extends the referenced element (<a> and the animation elements do neither)
    private const HREF_RENDERS_TARGET = ['use', 'pattern', 'linearGradient', 'radialGradient', 'filter', 'feImage'];

    // elements whose content renders only when something references it
    private const HIDDEN_ELEMENTS = ['defs', 'symbol', 'pattern', 'mask', 'clipPath', 'filter'];

    // url(#id) in an attribute value, quotes and whitespace allowed: url( "#id" ); captures the id. A quoted id runs to
    // its closing quote, since url('#p)q') references id="p)q"; (?| numbers each branch's capture as group 1
    private const URL_FRAGMENT = '/url\(\s*+(?|"\s*+#([^"]*)|\'\s*+#([^\']*)|#([^"\')\s]*))/i';

    /** @var array<int, string> id of each open element that has one, by depth */
    private array $openIds = [];

    /** @var array<string, int> elements inside each id, itself included */
    private array $elementsUnder = [];

    /** @var array<string, array<string, int>> how many times each id is referenced from inside each id */
    private array $referencesUnder = [];

    /** @var array<string, int> how many times each id is referenced by elements that render on their own, outside the hidden elements */
    private array $renderedReferences = [];

    /** depth of the outermost open hidden element */
    private ?int $hiddenDepth = null;

    /** ids in $elementsUnder plus entries in $renderedReferences and $referencesUnder so far */
    private int $referenceEntries = 0;

    private function noteReferences(XMLReader $reader): void
    {
        if ($this->tooManyReferences()) {
            return;
        }
        foreach ($this->openIds as $id) {
            $this->elementsUnder[$id]++;
        }
        $holders = $this->openIds;   // ids whose content includes this element's references
        $id      = $reader->getAttribute('id');
        if ($id !== null && $id !== '') {
            if (isset($this->elementsUnder[$id])) {
                $this->elementsUnder[$id]++;
            } else {
                $this->elementsUnder[$id] = 1;
                $this->referenceEntries++;
            }
            if ($reader->isEmptyElement) {
                $holders[] = $id;   // no END_ELEMENT will follow, so it never joins openIds
            } else {
                $this->openIds[$reader->depth] = $id;
                $holders[$reader->depth]       = $id;
            }
        }
        if ($reader->namespaceURI !== self::SVG_NS) {
            return;
        }
        $element = $reader->localName;
        if ($this->hiddenDepth === null && in_array($element, self::HIDDEN_ELEMENTS, true) && !$reader->isEmptyElement) {
            $this->hiddenDepth = $reader->depth;
        }

        $targets = [];
        if ($reader->moveToFirstAttribute()) {
            do {
                $value = $reader->value;
                if ($reader->localName === 'href' && ($reader->namespaceURI === '' || $reader->namespaceURI === self::XLINK_NS)) {
                    $value = trim($value);
                    if (str_starts_with($value, '#') && in_array($element, self::HREF_RENDERS_TARGET, true)) {
                        $targets[] = substr($value, 1);
                    }
                } elseif (stripos($value, 'url(') !== false) {
                    if (preg_match_all(self::URL_FRAGMENT, $value, $matches) === false) {   // a PCRE limit was reached, so the references are unknown
                        $this->fail('reference-expansion-too-large', 'could not be counted');
                    } else {
                        array_push($targets, ...$matches[1]);   // fill, stroke, mask, clip-path, filter, and style=
                    }
                }
            } while ($reader->moveToNextAttribute());
            $reader->moveToElement();
        }
        // an animation inside <defs> still changes the visible element it targets, so <set to="url(#p)"> counts as rendered anywhere
        $rendered = $this->hiddenDepth === null || in_array($element, self::ANIMATION_ELEMENTS, true);
        foreach ($targets as $target) {
            if ($this->tooManyReferences()) {   // checked per target: one element can carry thousands of url() attributes
                return;
            }
            $target = str_replace(["\t", "\n", "\r"], '', rtrim($target));   // a browser's URL parser drops these, so url('#p&#9;q ') renders id="pq"
            if (str_contains($target, '%')) {
                $target = rawurldecode($target);   // browsers decode the fragment before looking up the id, so #%61 renders id="a"
            }
            if ($rendered) {
                if (isset($this->renderedReferences[$target])) {
                    $this->renderedReferences[$target]++;
                } else {
                    $this->renderedReferences[$target] = 1;
                    $this->referenceEntries++;
                }
            }
            foreach ($holders as $holder) {   // runs once per reference per enclosing id, so no helper call here
                if (isset($this->referencesUnder[$holder][$target])) {
                    $this->referencesUnder[$holder][$target]++;
                } else {
                    $this->referencesUnder[$holder][$target] = 1;
                    $this->referenceEntries++;
                }
            }
        }
    }

    /**
     * Every id is stored, and every reference once per id it is nested in, so a file could
     * make the check hold references times nesting depth entries. Past the cap the file is
     * rejected and nothing more is stored.
     */
    private function tooManyReferences(): bool
    {
        if ($this->referenceEntries <= self::$maxReferenceEntries) {
            return false;
        }
        $this->fail('reference-expansion-too-large', 'need more than ' . number_format(self::$maxReferenceEntries) . ' records to track (ids and references)');
        return true;
    }

    private function noteEndElementForReferences(int $depth): void
    {
        unset($this->openIds[$depth]);
        if ($depth === $this->hiddenDepth) {
            $this->hiddenDepth = null;
        }
    }

    private function checkReferenceExpansion(): void
    {
        if ($this->tooManyReferences()) {
            return;   // the graph stopped short, so there is nothing sound to expand
        }
        $limit   = self::$maxExpandedElements + 1;   // totals are capped here so a bomb cannot overflow an int
        $counted = [];   // id => elements rendered by one reference to it
        $loop    = null;
        $total   = 0;
        foreach ($this->renderedReferences as $target => $times) {
            $total = min($total + $this->expandedElements((string) $target, $counted, $limit, $loop) * $times, $limit);   // a numeric id comes back from the key as an int
        }
        if ($loop !== null) {
            $this->fail('reference-expansion-too-large', 'form a loop (' . self::excerpt($loop) . ')');
        } elseif ($total > self::$maxExpandedElements) {
            $this->fail('reference-expansion-too-large', 'expand to more than ' . number_format(self::$maxExpandedElements) . ' elements');
        }
    }

    /**
     * Elements rendered by one reference to $start: its own, plus those of every id referenced
     * from inside it, and so on down. A depth-first walk with its own stack, since a reference
     * chain can be longer than PHP's call stack allows. $counted is filled in as ids finish, so
     * each id is expanded once. $loop gets the first reference loop found.
     *
     * @param array<string, int> $counted
     */
    private function expandedElements(string $start, array &$counted, int $limit, ?string &$loop): int
    {
        if (isset($counted[$start])) {
            return $counted[$start];
        }
        $frames = [$this->frame($start)];
        $onPath = [$start => true];
        while ($frames !== []) {
            $last                        = array_key_last($frames);
            [$id, $next, $sum, $targets] = $frames[$last];
            if ($next < count($targets)) {
                $target = $targets[$next];
                $times  = $this->referencesUnder[$id][$target];
                $frames[$last][1]++;
                if (isset($counted[$target])) {
                    $frames[$last][2] = min($sum + $counted[$target] * $times, $limit);
                } elseif (isset($onPath[$target])) {
                    $loop ??= implode(' -> ', array_map(fn(string $step) => "#$step", [...array_keys($onPath), $target]));
                    $frames[$last][2] = $limit;
                } else {
                    $onPath[$target] = true;
                    $frames[]        = $this->frame($target);
                }
                continue;
            }
            array_pop($frames);
            unset($onPath[$id]);
            $counted[$id] = $sum;
            if ($frames !== []) {
                $last  = array_key_last($frames);
                $times = $this->referencesUnder[$frames[$last][0]][$id];
                $frames[$last][2] = min($frames[$last][2] + $sum * $times, $limit);
            }
        }
        return $counted[$start];
    }

    /**
     * A stack frame for expandedElements(): [id, next target index, total so far, targets].
     * An undefined id renders nothing. A numeric id comes back from the array key as an int.
     *
     * @return array{string, int, int, string[]}
     */
    private function frame(string $id): array
    {
        return [$id, 0, $this->elementsUnder[$id] ?? 0, array_map(strval(...), array_keys($this->referencesUnder[$id] ?? []))];
    }

    //endregion
    //region Value Rules

    private function checkHref(string $element, string $value): void
    {
        $value = trim($value);
        if (str_starts_with($value, '#')) {
            return;
        }
        $shown = $value === '' ? '(empty)' : self::excerpt($value);
        if (!in_array($element, self::IMAGE_ELEMENTS, true)) {
            $this->fail('href-not-allowed', $shown);
            return;
        }
        if (!preg_match(self::DATA_IMAGE, $value, $match)) {
            $this->fail('image-href-not-allowed', $shown);
            return;
        }
        if (strtolower($match[1]) === 'svg+xml') {
            $this->checkEmbeddedSvg(substr($value, strlen($match[0])));
        }
    }

    /**
     * An SVG inside <image> renders in the browser's secure mode, so it cannot run script
     * there. Server-side rasterizers give it no such protection, so it gets the same check.
     */
    private function checkEmbeddedSvg(string $base64): void
    {
        if ($this->embedDepth >= self::$maxEmbedDepth) {
            $this->fail('embedded-svg-not-allowed', 'SVG images nested more than ' . self::$maxEmbedDepth . ' levels deep');
            return;
        }
        $svg = base64_decode($base64, true);
        if ($svg === false) {
            $this->fail('embedded-svg-not-allowed', 'the data: URL is not valid base64');
            return;
        }
        $this->libxmlError ??= libxml_get_errors()[0] ?? null;   // the inner parse clears the buffer this parse is still filling
        foreach (self::checkEmbedded($svg, $this->embedDepth + 1)->errors as $violation) {
            $this->fail('embedded-svg-not-allowed', $violation->message);
        }
    }

    /**
     * Applies to <style> text and style= values. With the backslash banned CSS has no escape
     * syntax, so every token reads as written and a regex sees the same thing a browser does.
     */
    private function checkCss(string $css): void
    {
        if (preg_match(self::CSS_BANNED, $css, $match) !== 0) {   // false (a PCRE limit) rejects too
            $this->fail('css-not-allowed', self::excerpt($match[0] ?? self::UNSCANNABLE));
        }
        $this->checkCssNames($css, self::CSS_FUNCTION, self::CSS_FUNCTIONS);
        $this->checkCssNames($css, self::CSS_AT_RULE, self::CSS_AT_RULES);
        if (preg_match(self::CSS_URL_NOT_ALLOWED, $css, $match) !== 0) {
            $this->fail('css-not-allowed', self::excerpt($match[0] ?? self::UNSCANNABLE));
        }
    }

    /**
     * Rejects every function or at-rule name in $css that is not in $allowed, with the name as
     * written as the detail: image-set( or @import. Comments and strings are scanned too.
     */
    private function checkCssNames(string $css, string $pattern, array $allowed): void
    {
        if (preg_match_all($pattern, $css, $matches) === false) {   // a PCRE limit was reached, so the names are unknown
            $this->fail('css-not-allowed', self::UNSCANNABLE);
            return;
        }
        foreach ($matches[1] as $index => $name) {
            if (!in_array(strtolower($name), $allowed, true)) {
                $this->fail('css-not-allowed', self::excerpt($matches[0][$index]));
            }
        }
    }

    /**
     * SMIL can rewrite an attribute after the check, so attributeName must name an allowed
     * attribute that changes no URL, CSS or reference. The values it sets (from, to, by,
     * values) get the same checks as any attribute value, and with href off the list, a
     * javascript: or https: value lands somewhere that does not load or run it.
     */
    private function checkAnimationTarget(string $attributeName): void
    {
        $target = trim($attributeName);
        $local  = substr(strrchr(":$target", ':'), 1);   // the name after the last colon; any prefix can be bound to XLink
        if (!in_array($local, self::ATTRIBUTES, true) || in_array($local, self::ANIMATION_TARGETS_EXCLUDED, true)) {   // data-*, aria-* and on* are not in ATTRIBUTES
            $this->fail('animation-target-not-allowed', self::excerpt($target));
        }
    }

    //endregion
    //region Helpers

    private static function isInertNamespace(string $namespace): bool
    {
        foreach (self::INERT_NAMESPACES as $inert) {
            if (str_starts_with($namespace, $inert)) {
                return true;
            }
        }
        return false;
    }

    /** The first $maxDetailLength characters of a value for an error message, cut on a UTF-8 boundary. */
    private static function excerpt(string $value): string
    {
        preg_match('/^.{0,' . self::$maxDetailLength . '}/us', $value, $match);
        $start = $match[0] ?? substr($value, 0, self::$maxDetailLength);
        return strlen($start) < strlen($value) ? "$start..." : $start;
    }

    /**
     * Escapes control characters as \n or \000, and bytes above 127 as \351 when the text is not
     * valid UTF-8, so a detail never spans lines and always encodes as UTF-8. A log line or an
     * error page can then show it as is.
     */
    private static function escapeAsOneLine(string $detail): string
    {
        $detail = addcslashes($detail, "\0..\37\177");
        if (!preg_match('//u', $detail)) {
            $detail = addcslashes($detail, "\200..\377");
        }
        return $detail;
    }

    //endregion
}
