<?php

declare(strict_types=1);

namespace Niang\Core;

use Niang\Core\Validation\FormRequest;

/**
 * Description OpenAPI 3.0 générée à partir des routes réelles (voir `niang openapi`) : jamais
 * retapée, elle ne peut pas diverger du code. Ce qui est déduit :
 *  - chemins, méthodes, paramètres de chemin (typés integer si leur contrainte where() est numérique) ;
 *  - corps de requête : règles de la FormRequest injectée dans l'action (required, string, integer,
 *    numeric, boolean, array, email, url, date, uuid, in, min, max, nullable, file, image...) ;
 *  - authentification : middlewares Authenticate/Authorize (session) et AuthenticateWithToken (Bearer) ;
 *  - résumé et description : docblock de l'action ;
 *  - réponses 401, 403, 404 (liaison de modèle), 422 (validation), 429 (limitation de débit).
 * Non déduit : une validation écrite dans le corps de l'action ($this->validate(...)) et la forme des
 * réponses — complétez au besoin le fichier généré.
 *
 * @experimental le format de sortie peut encore évoluer (voir docs/API_STABILITY.md).
 */
final class OpenApi
{
    /**
     * @param array{prefix?: string, title?: string, version?: string, server?: string} $options
     * @return array<string, mixed>
     */
    public static function generate(Router $router, array $options = []): array
    {
        $prefix = rtrim($options['prefix'] ?? '/api', '/');
        $paths = [];
        $usesBearer = $usesSession = false;

        foreach ($router->routes() as $route) {
            $uri = $route['uri'];

            if (($prefix !== '' && $uri !== $prefix && !str_starts_with($uri, "$prefix/")) || in_array($route['method'], ['OPTIONS', 'HEAD'], true)) {
                continue;
            }

            [$operation, $bearer, $session] = self::operation($route, $prefix);
            $usesBearer = $usesBearer || $bearer;
            $usesSession = $usesSession || $session;
            $paths[preg_replace('/\{(\w+)\??\}/', '{$1}', $uri)][strtolower($route['method'])] = $operation;
        }

        ksort($paths);

        $document = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $options['title'] ?? (string) Env::get('APP_NAME', 'NiangPro') . ' API',
                'version' => $options['version'] ?? '1.0.0',
            ],
            'paths' => $paths === [] ? new \stdClass() : $paths,
        ];

        $server = $options['server'] ?? (string) Env::get('APP_URL', '');
        if ($server !== '') {
            $document['servers'] = [['url' => rtrim($server, '/')]];
        }

        $schemes = [];
        if ($usesBearer) {
            $schemes['bearerAuth'] = ['type' => 'http', 'scheme' => 'bearer'];
        }
        if ($usesSession) {
            $schemes['sessionAuth'] = ['type' => 'apiKey', 'in' => 'cookie', 'name' => (string) (session_name() ?: 'PHPSESSID')];
        }
        if ($schemes !== []) {
            $document['components'] = ['securitySchemes' => $schemes];
        }

        return $document;
    }

    /** @return array{0: array<string, mixed>, 1: bool, 2: bool} opération, Bearer ?, session ? */
    private static function operation(array $route, string $prefix): array
    {
        $middleware = array_values(array_map(fn (string $m) => explode(':', $m, 2)[0], $route['middleware'] ?? []));
        $bearer = self::hasMiddleware($middleware, 'AuthenticateWithToken');
        $session = !$bearer && (self::hasMiddleware($middleware, 'Authenticate') || self::hasMiddleware($middleware, 'Authorize'));
        $reflection = self::reflectAction($route['action']);
        [$summary, $description] = self::docblock($reflection);

        $operation = [
            'operationId' => $route['name'] ?? strtolower($route['method']) . preg_replace('/[^A-Za-z0-9]+/', '_', $route['uri']),
            'tags' => [self::tag($route['uri'], $prefix)],
        ];

        if ($summary !== null) {
            $operation['summary'] = $summary;
        }
        if ($description !== null) {
            $operation['description'] = $description;
        }

        $parameters = self::pathParameters($route);
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        $responses = ['200' => ['description' => 'Succès']];
        $rules = $reflection !== null ? self::formRequestRules($reflection) : null;

        if ($rules !== null && in_array($route['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $schema = self::schema($rules);
            // Un fichier ne part qu'en multipart/form-data ; sinon JSON ou formulaire classique.
            $content = str_contains((string) json_encode($schema), '"binary"')
                ? ['multipart/form-data' => ['schema' => $schema]]
                : ['application/json' => ['schema' => $schema], 'application/x-www-form-urlencoded' => ['schema' => $schema]];
            $operation['requestBody'] = ['required' => ($schema['required'] ?? []) !== [], 'content' => $content];
        }

        if ($bearer || $session) {
            $operation['security'] = [[$bearer ? 'bearerAuth' : 'sessionAuth' => []]];
            $responses['401'] = ['description' => 'Non authentifié'];
        }
        if (self::hasMiddleware($middleware, 'Authorize')) {
            $responses['403'] = ['description' => 'Action non autorisée'];
        }
        if (!empty($route['bindings'])) {
            $responses['404'] = ['description' => 'Ressource introuvable'];
        }
        if ($rules !== null) {
            $responses['422'] = ['description' => 'Données invalides'];
        }
        if (self::hasMiddleware($middleware, 'ThrottleRequests')) {
            $responses['429'] = ['description' => 'Trop de requêtes'];
        }

        $operation['responses'] = $responses;

        return [$operation, $bearer, $session];
    }

    /** @param list<string> $middleware */
    private static function hasMiddleware(array $middleware, string $shortName): bool
    {
        foreach ($middleware as $class) {
            $parts = explode('\\', $class);
            if (end($parts) === $shortName) {
                return true;
            }
        }

        return false;
    }

    private static function tag(string $uri, string $prefix): string
    {
        $rest = trim(substr($uri, strlen($prefix)), '/');
        $first = explode('/', $rest)[0];

        return $first === '' || str_starts_with($first, '{') ? 'default' : $first;
    }

    /** @return list<array<string, mixed>> */
    private static function pathParameters(array $route): array
    {
        preg_match_all('/\{(\w+)\}/', $route['uri'], $names);
        $parameters = [];

        foreach ($names[1] as $name) {
            $numeric = preg_match('/\(\?P<' . preg_quote($name, '/') . '>(\[0-9\]\+|\\\\d\+)\)/', (string) ($route['pattern'] ?? '')) === 1;
            $binding = $route['bindings'][$name] ?? null;
            $parameter = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => $numeric ? 'integer' : 'string']];

            if ($binding !== null) {
                $model = explode('\\', $binding[0]);
                $parameter['description'] = end($model) . " (colonne {$binding[1]})";
            }

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    private static function reflectAction(mixed $action): ?\ReflectionFunctionAbstract
    {
        try {
            if ($action instanceof \Closure) {
                return new \ReflectionFunction($action);
            }

            if (is_string($action) && str_contains($action, '@')) {
                $action = explode('@', $action, 2);
            }

            return is_array($action) && method_exists($action[0], $action[1]) ? new \ReflectionMethod($action[0], $action[1]) : null;
        } catch (\ReflectionException) {
            return null;
        }
    }

    /** @return array{0: ?string, 1: ?string} première ligne, puis le reste */
    private static function docblock(?\ReflectionFunctionAbstract $reflection): array
    {
        $doc = $reflection?->getDocComment();

        if ($doc === false || $doc === null) {
            return [null, null];
        }

        $lines = [];
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim(preg_replace(['#^\s*(/\*\*|\*/|\*)#', '#\*/\s*$#'], '', $line) ?? '');
            if (str_starts_with($line, '@')) {
                break;
            }
            $lines[] = $line;
        }

        $text = trim(implode("\n", $lines));
        if ($text === '') {
            return [null, null];
        }

        [$summary, $rest] = array_pad(explode("\n", $text, 2), 2, '');
        $rest = trim($rest);

        return [$summary, $rest === '' ? null : $rest];
    }

    /** @return array<string, mixed>|null règles de la FormRequest injectée dans l'action, ou null */
    private static function formRequestRules(\ReflectionFunctionAbstract $reflection): ?array
    {
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class)) {
                try {
                    /** @var FormRequest $request */
                    $request = (new \ReflectionClass($type->getName()))->newInstanceWithoutConstructor();

                    return $request->rules();
                } catch (\Throwable) {
                    return null; // des règles qui lisent la requête elle-même : non déductibles hors requête
                }
            }
        }

        return null;
    }

    /**
     * Règles de validation → schéma JSON. Les champs « liste.* » décrivent les éléments d'un tableau.
     *
     * @param array<string, string|array<int, mixed>> $rules
     * @return array<string, mixed>
     */
    public static function schema(array $rules): array
    {
        $properties = [];
        $required = [];
        $items = [];

        foreach ($rules as $field => $ruleSet) {
            $list = is_array($ruleSet) ? array_values(array_filter($ruleSet, 'is_string')) : explode('|', (string) $ruleSet);

            if (str_contains($field, '.*')) {
                [$parent, $child] = array_pad(explode('.*', $field, 2), 2, '');
                $items[$parent][ltrim($child, '.')] = $list;
                continue;
            }

            $properties[$field] = self::property($list);

            if (in_array('required', $list, true)) {
                $required[] = $field;
            }
        }

        foreach ($items as $parent => $children) {
            $itemSchema = isset($children['']) ? self::property($children['']) : self::schema(array_filter($children, fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY));
            $properties[$parent] = ($properties[$parent] ?? ['type' => 'array']) + ['items' => $itemSchema];
            $properties[$parent]['type'] = 'array';
        }

        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param list<string> $rules
     * @return array<string, mixed>
     */
    private static function property(array $rules): array
    {
        $property = ['type' => 'string'];
        $numeric = false;

        foreach ($rules as $rule) {
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

            match ($name) {
                'integer' => [$property['type'], $numeric] = ['integer', true],
                'numeric' => [$property['type'], $numeric] = ['number', true],
                'boolean' => $property['type'] = 'boolean',
                'array' => $property['type'] = 'array',
                'email' => $property['format'] = 'email',
                'url' => $property['format'] = 'uri',
                'uuid' => $property['format'] = 'uuid',
                'date' => $property['format'] = 'date',
                'file', 'image' => $property['format'] = 'binary',
                'nullable' => $property['nullable'] = true,
                'in' => $property['enum'] = explode(',', (string) $param),
                default => null,
            };
        }

        foreach ($rules as $rule) {
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

            if (!in_array($name, ['min', 'max'], true) || !is_numeric($param)) {
                continue;
            }

            $key = match (true) {
                $numeric => $name === 'min' ? 'minimum' : 'maximum',
                $property['type'] === 'array' => $name === 'min' ? 'minItems' : 'maxItems',
                ($property['format'] ?? null) === 'binary' => null, // taille de fichier en Ko : pas d'équivalent
                default => $name === 'min' ? 'minLength' : 'maxLength',
            };

            if ($key !== null) {
                $property[$key] = $numeric ? $param + 0 : (int) $param;
            }
        }

        return $property;
    }
}
