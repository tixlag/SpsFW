<?php

declare(strict_types=1);

/**
 * M8b fixture for {@see \SpsFW\Core\Compile\Introspection\ResponseAstAnalyzerTest}: one method per inference
 * case. The DTOs live in THIS file's namespace so the analyzer resolves `new FooDto()` to a real FQCN through
 * NameResolver (file parsed + resolved once). Only the analyzer reads these (no routing / no schema eligibility
 * assumed). A second controller and a trait consumer exercise FQCN/trait-based method location (D5): two
 * same-named methods in different classes, and a method defined in a used trait, must never mix.
 */

namespace SpsFW\Tests\Compile\Introspection\Fixtures;

use SpsFW\Core\Http\Response;

class AstUserDto
{
    public string $id = '';
}

class AstItemDto
{
    public string $sku = '';
}

class ResponseAstFixtureController
{
    // A — direct `new Dto()`.
    public function caseA(): Response
    {
        return Response::json(new AstUserDto());
    }

    // B — local variable assigned a DTO, then returned.
    public function caseB(): Response
    {
        $user = new AstUserDto();
        return Response::json($user);
    }

    // C — homogeneous literal list of one DTO.
    public function caseC(): Response
    {
        return Response::json([new AstItemDto(), new AstItemDto()]);
    }

    // D — same DTO across several success branches.
    public function caseD(bool $ok): Response
    {
        if ($ok) {
            return Response::json(new AstUserDto());
        }
        return Response::json(new AstUserDto());
    }

    // E — error / throw branches are ignored; the success body is still AstUserDto.
    public function caseE(): Response
    {
        if (random_int(0, 1) === 1) {
            return Response::error(null, statusCode: 404);
        }
        return Response::json(new AstUserDto());
    }

    // F — callee inferred via its declared single-class return type (same-class method, single hop).
    public function caseF(): Response
    {
        return Response::json($this->makeUser());
    }

    public function makeUser(): AstUserDto
    {
        return new AstUserDto();
    }

    // divergent success branches ⇒ non-definite.
    public function divergent(bool $ok): Response
    {
        if ($ok) {
            return Response::json(new AstUserDto());
        }
        return Response::json(new AstItemDto());
    }

    // loop-built array ⇒ non-definite (element shape not statically known).
    public function opaque(): Response
    {
        $list = [];
        for ($i = 0; $i < 3; $i++) {
            $list[] = new AstItemDto();
        }
        return Response::json($list);
    }

    // array_map / callback ⇒ non-definite.
    public function mapped(): Response
    {
        $items = array_map(static fn(): AstItemDto => new AstItemDto(), range(1, 3));
        return Response::json($items);
    }

    // literal 201 via the json status argument.
    public function created(): Response
    {
        return Response::json(new AstUserDto(), 201);
    }

    // literal 201 via a named status argument.
    public function createdNamed(): Response
    {
        return Response::json(new AstUserDto(), status: 201);
    }

    // 204 via Response::noContent().
    public function noContent(): Response
    {
        return Response::noContent();
    }

    // a literal error status (404) — success body is empty.
    public function notFound(): Response
    {
        return Response::error(null, statusCode: 404);
    }

    // the SAME DTO across branches but DIFFERENT success statuses (200/201) — schema is definite, status is NOT
    // (statusConflict=true; the single-status model cannot represent it).
    public function sameDtoDifferentStatus(bool $ok): Response
    {
        if ($ok) {
            return Response::json(new AstUserDto(), 201);
        }
        return Response::json(new AstUserDto(), 200);
    }
}

// A second controller defining a SAME-NAMED method (caseA) over a DIFFERENT DTO — the analyzer must locate the
// method by the declaring-class FQCN, never by short name, so the two never mix (D5).
class ResponseAstAltController
{
    public function caseA(): Response
    {
        return Response::json(new AstItemDto());
    }
}

// A method defined in a trait and consumed via `use` — reflection reports the TRAIT as the declaring class, so
// the analyzer must locate the method in the Trait_ node by its FQCN (D5).
trait AstTraitFixture
{
    public function traitMethod(): Response
    {
        return Response::json(new AstUserDto());
    }
}

class ResponseAstTraitConsumer
{
    use AstTraitFixture;
}
