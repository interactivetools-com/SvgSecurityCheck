<?php
declare(strict_types=1);

namespace Itools\SvgSecurityCheck;

/**
 * What SvgSecurityCheck::checkFile() and checkString() return.
 *
 *     $result = SvgSecurityCheck::checkFile($path);
 *     if ($result->ok) {
 *         // store the file
 *     }
 *     foreach ($result->errors as $violation) {
 *         echo htmlspecialchars($violation->message), "<br>";
 *     }
 *
 * errors holds one Violation per distinct problem, in file order, at most 50. The list
 * ends early when the XML is malformed, since parsing cannot continue past that point.
 * Every value in a Violation is plain text taken from the uploaded file, so HTML-encode
 * it before output.
 */
final class Result
{
    /** true when $errors is empty */
    public readonly bool $ok;

    /** @param Violation[] $errors */
    public function __construct(public readonly array $errors)
    {
        $this->ok = $errors === [];
    }
}
