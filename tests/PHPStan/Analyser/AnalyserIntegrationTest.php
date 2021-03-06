<?php declare(strict_types = 1);

namespace PHPStan\Analyser;

use PHPStan\Broker\Broker;
use PHPStan\File\FileHelper;
use PHPStan\Reflection\SignatureMap\SignatureMapProvider;
use const PHP_VERSION_ID;

class AnalyserIntegrationTest extends \PHPStan\Testing\TestCase
{

	public function testUndefinedVariableFromAssignErrorHasLine(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/data/undefined-variable-assign.php');
		$this->assertCount(1, $errors);
		$error = $errors[0];
		$this->assertSame('Undefined variable: $bar', $error->getMessage());
		$this->assertSame(3, $error->getLine());
	}

	public function testMissingPropertyAndMethod(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/../../notAutoloaded/Foo.php');
		$this->assertCount(4, $errors);
		$this->assertStringContainsString('Constant FOO_CONST was not found in reflection of class PHPStan\Tests\Foo - probably the wrong version of class is autoloaded. The currently loaded version is at', $errors[0]->getMessage());
		$this->assertSame(8, $errors[0]->getLine());
		$this->assertStringContainsString('Property $fooProperty was not found in reflection of class PHPStan\Tests\Foo - probably the wrong version of class is autoloaded. The currently loaded version is at', $errors[1]->getMessage());
		$this->assertSame(11, $errors[1]->getLine());
		$this->assertStringContainsString('Method doFoo() was not found in reflection of class PHPStan\Tests\Foo - probably the wrong version of class is autoloaded. The currently loaded version is at', $errors[2]->getMessage());
		$this->assertSame(13, $errors[2]->getLine());
		$this->assertStringContainsString('Access to an undefined property PHPStan\Tests\Foo::$fooProperty.', $errors[3]->getMessage());
		$this->assertSame(15, $errors[3]->getLine());
	}

	public function testMissingClassErrorAboutMisconfiguredAutoloader(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/../../notAutoloaded/Bar.php');
		$this->assertCount(1, $errors);
		$error = $errors[0];
		$this->assertSame('Class PHPStan\Tests\Bar was not found while trying to analyse it - autoloading is probably not configured properly.', $error->getMessage());
		$this->assertNull($error->getLine());
	}

	public function testMissingFunctionErrorAboutMisconfiguredAutoloader(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/../../notAutoloaded/functionFoo.php');
		$this->assertCount(2, $errors);
		$this->assertSame('Function PHPStan\Tests\foo not found while trying to analyse it - autoloading is probably not configured properly.', $errors[0]->getMessage());
		$this->assertSame(5, $errors[0]->getLine());
		$this->assertSame('Function doSomething not found.', $errors[1]->getMessage());
		$this->assertSame(7, $errors[1]->getLine());
	}

	public function testAnonymousClassWithInheritedConstructor(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/data/anonymous-class-with-inherited-constructor.php');
		$this->assertCount(0, $errors);
	}

	public function testNestedFunctionCallsDoNotCauseExcessiveFunctionNesting(): void
	{
		if (extension_loaded('xdebug')) {
			$this->markTestSkipped('This test takes too long with XDebug enabled.');
		}
		$errors = $this->runAnalyse(__DIR__ . '/data/nested-functions.php');
		$this->assertCount(0, $errors);
	}

	public function testExtendingUnknownClass(): void
	{
		if (PHP_VERSION_ID >= 70400) {
			$this->markTestSkipped('Not supported on PHP 7.4 - results in fatal error.');
		}
		$errors = $this->runAnalyse(__DIR__ . '/data/extending-unknown-class.php');
		$this->assertCount(1, $errors);
		$this->assertNull($errors[0]->getLine());
		$this->assertSame('Class ExtendingUnknownClass\Bar not found and could not be autoloaded.', $errors[0]->getMessage());
	}

	public function testExtendingKnownClassWithCheck(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/data/extending-known-class-with-check.php');
		$this->assertCount(1, $errors);
		$this->assertSame('Class ExtendingKnownClassWithCheck\Bar not found.', $errors[0]->getMessage());
		$this->assertSame(5, $errors[0]->getLine());

		$broker = self::getContainer()->getByType(Broker::class);
		$this->assertTrue($broker->hasClass(\ExtendingKnownClassWithCheck\Foo::class));
	}

	public function testInfiniteRecursionWithCallable(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/data/Foo-callable.php');
		$this->assertCount(0, $errors);
	}

	public function testClassThatExtendsUnknownClassIn3rdPartyPropertyTypeShouldNotCauseAutoloading(): void
	{
		// no error about PHPStan\Tests\Baz not being able to be autoloaded
		$errors = $this->runAnalyse(__DIR__ . '/data/ExtendsClassWithUnknownPropertyType.php');
		$this->assertCount(1, $errors);
		//$this->assertSame(11, $errors[0]->getLine());
		$this->assertSame('Call to an undefined method ExtendsClassWithUnknownPropertyType::foo().', $errors[0]->getMessage());
	}

	public function testAnonymousClassesWithComments(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/data/AnonymousClassesWithComments.php');
		$this->assertCount(3, $errors);
		foreach ($errors as $error) {
			$this->assertStringContainsString('Call to an undefined method', $error->getMessage());
		}
	}

	public function testUniversalObjectCrateIssue(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/data/universal-object-crate.php');
		$this->assertCount(1, $errors);
		$this->assertSame('Parameter #1 $i of method UniversalObjectCrate\Foo::doBaz() expects int, string given.', $errors[0]->getMessage());
		$this->assertSame(19, $errors[0]->getLine());
	}

	public function testCustomFunctionWithNameEquivalentInSignatureMap(): void
	{
		$signatureMapProvider = self::getContainer()->getByType(SignatureMapProvider::class);
		if (!$signatureMapProvider->hasFunctionSignature('bcompiler_write_file')) {
			$this->fail();
		}
		require_once __DIR__ . '/data/custom-function-in-signature-map.php';
		$errors = $this->runAnalyse(__DIR__ . '/data/custom-function-in-signature-map.php');
		$this->assertCount(0, $errors);
	}

	public function testAnonymousClassWithWrongFilename(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/data/anonymous-class-wrong-filename-regression.php');
		$this->assertCount(5, $errors);
		$this->assertStringContainsString('Return typehint of method', $errors[0]->getMessage());
		$this->assertSame(16, $errors[0]->getLine());
		$this->assertStringContainsString('Return typehint of method', $errors[1]->getMessage());
		$this->assertSame(16, $errors[1]->getLine());
		$this->assertSame('Instantiated class AnonymousClassWrongFilename\Bar not found.', $errors[2]->getMessage());
		$this->assertSame(18, $errors[2]->getLine());
		$this->assertStringContainsString('Parameter #1 $test of method', $errors[3]->getMessage());
		$this->assertStringContainsString('$this(AnonymousClassWrongFilename\Foo) given', $errors[3]->getMessage());
		$this->assertSame(23, $errors[3]->getLine());
		$this->assertSame('Call to method test() on an unknown class AnonymousClassWrongFilename\Bar.', $errors[4]->getMessage());
		$this->assertSame(24, $errors[4]->getLine());
	}

	public function testExtendsPdoStatementCrash(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/data/extends-pdo-statement.php');
		$this->assertCount(0, $errors);
	}

	public function testArrayDestructuringArrayDimFetch(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/data/array-destructuring-array-dim-fetch.php');
		$this->assertCount(0, $errors);
	}

	public function testNestedNamespaces(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/data/nested-namespaces.php');
		$this->assertCount(2, $errors);
		$this->assertSame('Property y\x::$baz has unknown class x\baz as its type.', $errors[0]->getMessage());
		$this->assertSame(15, $errors[0]->getLine());
		$this->assertSame('Parameter $baz of method y\x::__construct() has invalid typehint type x\baz.', $errors[1]->getMessage());
		$this->assertSame(16, $errors[1]->getLine());
	}

	public function testClassExistsAutoloadingError(): void
	{
		$errors = $this->runAnalyse(__DIR__ . '/data/class-exists.php');
		$this->assertCount(1, $errors);
		$this->assertSame('Instantiated class \PHPStan\GitHubIssue2359 not found.', $errors[0]->getMessage());
		$this->assertSame(12, $errors[0]->getLine());
	}

	public function testCollectWarnings(): void
	{
		restore_error_handler();
		$errors = $this->runAnalyse(__DIR__ . '/data/declaration-warning.php');
		$this->assertCount(1, $errors);
		$this->assertSame('Declaration of DeclarationWarning\Bar::doFoo(int $i): void should be compatible with DeclarationWarning\Foo::doFoo(): void', $errors[0]->getMessage());
		$this->assertSame(__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'declaration-warning.php', $errors[0]->getFile());
		$this->assertSame(PHP_VERSION_ID >= 70400 ? 22 : 27, $errors[0]->getLine());
	}

	/**
	 * @param string $file
	 * @return \PHPStan\Analyser\Error[]
	 */
	private function runAnalyse(string $file): array
	{
		$file = $this->getFileHelper()->normalizePath($file);
		/** @var \PHPStan\Analyser\Analyser $analyser */
		$analyser = self::getContainer()->getByType(Analyser::class);
		/** @var \PHPStan\File\FileHelper $fileHelper */
		$fileHelper = self::getContainer()->getByType(FileHelper::class);
		/** @var \PHPStan\Analyser\Error[] $errors */
		$errors = $analyser->analyse([$file], false);
		foreach ($errors as $error) {
			$this->assertSame($fileHelper->normalizePath($file), $error->getFile());
		}

		return $errors;
	}

}
