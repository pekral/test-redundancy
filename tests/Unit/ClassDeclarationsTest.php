<?php

declare(strict_types = 1);

use Pekral\TestRedundancy\Fingerprint\ClassDeclarations;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

function declarationsProject(): string
{
    $project = temporaryDirectory();
    mkdir($project . '/src/Enums', recursive: true);
    mkdir($project . '/vendor/acme/src', recursive: true);
    file_put_contents($project . '/composer.json', (string) json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'src/', 'Vendored\\' => 'vendor/acme/src/', 'Broken\\' => 5]],
        'autoload-dev' => ['psr-4' => ['App\\Enums\\' => ['src/Enums/']]],
    ]));
    file_put_contents($project . '/src/Enums/Suit.php', <<<'PHP'
        <?php
        namespace App\Enums;

        enum Suit: string
        {
            case Hearts = 'H';
            case Joker = self::Hearts->value;
            public const string LABEL = 'suit';
            public const int COUNT = 4;
            public const string DERIVED = self::LABEL . 's';
        }
        PHP);
    file_put_contents($project . '/src/Enums/Number.php', "<?php\nnamespace App\\Enums;\n\nenum Number: int\n{\n    case One = 1;\n}\n");
    file_put_contents($project . '/src/Enums/Pure.php', "<?php\nnamespace App\\Enums;\n\nenum Pure\n{\n    case One;\n}\n");
    file_put_contents($project . '/src/Enums/Elsewhere.php', "<?php\nnamespace App\\Other;\n\nenum Elsewhere\n{\n    case One;\n}\n");
    file_put_contents($project . '/src/Enums/Broken.php', '<?php enum Broken {');
    file_put_contents($project . '/src/Enums/Large.php', "<?php\nnamespace App\\Enums;\n\nenum Large\n{\n    case One;\n}\n" . str_repeat('//', 300_000));
    file_put_contents($project . '/vendor/acme/src/Remote.php', "<?php\nnamespace Vendored;\n\nenum Remote\n{\n    case One;\n}\n");
    file_put_contents(sys_get_temp_dir() . '/Outside.php', "<?php\nnamespace App\\Enums;\n\nenum Outside\n{\n    case One;\n}\n");
    symlink(sys_get_temp_dir() . '/Outside.php', $project . '/src/Enums/Outside.php');

    return $project;
}

function classConstFetch(string $expression): ClassConstFetch
{
    $statements = new ParserFactory()->createForHostVersion()->parse(
        "<?php\nnamespace Tests;\n\nuse App\\Enums\\Suit;\nuse App\\Enums;\n\n{$expression};",
    ) ?? [];
    new NodeTraverser(new NameResolver(new Collecting(), ['replaceNodes' => false]))->traverse($statements);
    $fetch = new NodeFinder()->findFirstInstanceOf($statements, ClassConstFetch::class);
    assert($fetch instanceof ClassConstFetch);

    return $fetch;
}

it('reads an enum case and a class constant of a class the project maps through psr-4 (#187)', function (): void {
    $project = declarationsProject();
    $declarations = ClassDeclarations::forProject($project);

    expect($declarations->enumCase(classConstFetch('Suit::Hearts')))->toBe(['value' => 'H'])
        ->and($declarations->enumCase(classConstFetch('Enums\Number::One')))->toBe(['value' => 1])
        ->and($declarations->enumCase(classConstFetch('\App\Enums\Pure::One')))->toBe(['value' => null])
        ->and($declarations->constant(classConstFetch('Suit::LABEL')))->toBe('suit')
        ->and($declarations->constant(classConstFetch('Suit::COUNT')))->toBe(4)
        ->and(ClassDeclarations::className(classConstFetch('Suit::Hearts')))->toBe('App\Enums\Suit');

    removeDirectory($project);
    unlink(sys_get_temp_dir() . '/Outside.php');
});

it('reads nothing it cannot read safely (#187)', function (string $expression): void {
    $project = declarationsProject();
    $declarations = ClassDeclarations::forProject($project);

    expect($declarations->enumCase(classConstFetch($expression)))->toBeNull()
        ->and($declarations->constant(classConstFetch($expression)))->toBeNull();

    removeDirectory($project);
    unlink(sys_get_temp_dir() . '/Outside.php');
})->with([
    'a case whose value is an expression' => ['Suit::Joker'],
    'a class name built at runtime' => ['$class::One'],
    'a class name with a character the path guard rejects' => ["\\App\\Enums\\Caf\u{e9}::One"],
    'a class outside the psr-4 maps' => ['\Unmapped\Suit::Hearts'],
    'a constant whose value is an expression' => ['Suit::DERIVED'],
    'a file over the size limit' => ['\App\Enums\Large::One'],
    'a file that cannot be parsed' => ['\App\Enums\Broken::One'],
    'a file that declares the class in another namespace' => ['\App\Enums\Elsewhere::One'],
    'a file under vendor' => ['\Vendored\Remote::One'],
    'a missing case' => ['Suit::Missing'],
    'a special class name' => ['self::One'],
    'a symlink out of the project root' => ['\App\Enums\Outside::One'],
]);

it('reads no declaration without a composer.json and never autoloads the class (#187)', function (): void {
    $project = temporaryDirectory();
    $requested = new ArrayObject();
    $autoloader = static function (string $class) use ($requested): void {
        $requested->append($class);
    };
    spl_autoload_register($autoloader);

    $declarations = ClassDeclarations::forProject($project);
    $missing = $declarations->enumCase(classConstFetch('Suit::Hearts'));
    $declared = ClassDeclarations::forProject(declarationsProject())->enumCase(classConstFetch('Suit::Hearts'));

    spl_autoload_unregister($autoloader);

    expect($missing)->toBeNull()
        ->and($declared)->toBe(['value' => 'H'])
        ->and($requested->getArrayCopy())->toBe([]);

    removeDirectory($project);
    unlink(sys_get_temp_dir() . '/Outside.php');
});
