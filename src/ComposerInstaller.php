<?php

namespace Thunbolt\Composer;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Composer;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;

class ComposerInstaller extends LibraryInstaller {

	const PATHS = [
		'appDir' => 'app',
		'wwwDir' => 'www',
		'binDir' => 'bin',
		'insDir' => 'ins',
		'cfgDir' => self::PATHS['appDir'] . '/config',
		'modDir' => self::PATHS['appDir'] . '/modules',
		'resourceFile' => self::PATHS['appDir'] . '/config/composer.json',
		'resourceDir' => self::PATHS['wwwDir'] . '/ins-assets',
		'binConfigs' => self::PATHS['bin'] . '/config.json',
		'templateLoader' => self::PATHS['bin'] . '/loaders/template.json'
	];

	/** @var array */
	private $types = array(
		"thunbolt-module" => "ins/modules",
		"thunbolt-bin" => "bin",
		"thunbolt-component" => "ins/components",
		"thunbolt-package" => "ins/packages",
		"thunbolt-command" => "ins/commands"
	);

	/** @var array */
	protected $jsonData = array();

	/** @var array */
	protected $templateLoaderData = array();

	/**
	 * {@inheritDoc}
	 */
	public function getInstallPath(PackageInterface $package) {
		$type = $package->getType();
		$extra = $package->getExtra();

		if ($type === 'thunbolt-package' && isset($extra['packageName'])) {
			return $this->types[$type] . '/' . $extra['packageName'];
		}

		return $this->types[$type]. '/' . $package->getPrettyName();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize() {
		$this->filesystem->ensureDirectoryExists(self::PATHS['appDir']);
		$this->filesystem->ensureDirectoryExists(self::PATHS['resourceDir']);
		$this->filesystem->ensureDirectoryExists(self::PATHS['cfgDir']);

		$this->jsonData = file_exists(self::PATHS['resourceFile'] ? json_decode(file_get_contents(self::PATHS['resourceFile']), TRUE) : array();
		if (!array_key_exists('assets', $this->jsonData)) {
			$this->jsonData['assets'] = [];
		}
		if (!array_key_exists('configs', $this->jsonData)) {
			$this->jsonData['configs'] = [];
		}

		$this->templateLoaderData = file_exists(self::PATHS['templateLoader']) ? json_decode(file_get_contents(self::PATHS['templateLoader']), TRUE) : array();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function finish() {
		array_unique($this->jsonData['assets']);
		file_put_contents(self::PATHS['resourceFile'], json_encode($this->jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		file_put_contents(self::PATHS['templateLoader'], json_decode($this->templateLoaderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * {@inheritDoc}
	 */
	public function install(InstalledRepositoryInterface $repo, PackageInterface $package) {
		parent::install($repo, $package);

		$this->initialize();
		$this->installTemplateLoader($package);
		$this->installConfigs($package);
		$this->installAssets($package);
		$this->finish();
	}

	/**
	 * {@inheritDoc}
	 */
	public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target) {
		if ($this->isInstalled($repo, $initial)) {
			$this->initialize();
			$this->uninstallTemplateLoader($initial);
			$this->installTemplateLoader($target);
			$this->uninstallConfigs($initial);
			$this->installConfigs($target);
			$this->uninstallAssets($initial);
			$this->installAssets($target);
			$this->finish();
		}

		parent::update($repo, $initial, $target);
	}

	/**
	 * {@inheritDoc}
	 */
	public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package) {
		if ($this->isInstalled($repo, $package)) {
			$this->initialize();
			$this->uninstallTemplateLoader($package);
			$this->uninstallConfigs($package);
			$this->uninstallAssets($package);
			$this->finish();
		}

		parent::uninstall($repo, $package);
	}

	/**
	 * Only for "type": "thunbolt-bin"
	 * extra: {
	 * 		"configs": ["dir/config.neon"]
	 * }
	 * {@inheritDoc}
	 */
	protected function installTemplateLoader(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['configs']) || $package->getType() !== 'thunbolt-bin') {
			return;
		}

		foreach ($extra['configs'] as $config) {
			if (!file_exists($this->getInstallPath($package) . '/' . $config)) {
				$this->io->write("<warning>Skipped installation of '$config' for package " . $package->getName() . " file not found in package.</warning>");
			}

			$this->templateLoaderData[] = $this->getInstallPath($package) . '/' . $config;
		}
	}

	/**
	 * Only for "type": "thunbolt-bin"
	 * extra: {
	 * 		"configs": ["dir/config.neon"]
	 * }
	 * {@inheritDoc}
	 */
	protected function uninstallTemplateLoader(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['configs']) || $package->getType() !== 'thunbolt-bin') {
			return;
		}

		foreach ($extra['configs'] as $config) {
			if (($key = array_search($this->getInstallPath($package) . '/' . $config, $this->jsonData['configs'])) !== FALSE) {
				unset($this->templateLoaderData['configs'][$key]);
			}
		}
	}

	/**
	 * extra: {
	 * 		"configs": ["dir/config.neon"]
	 * }
	 * {@inheritDoc}
	 */
	protected function installConfigs(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['configs']) || $package->getType() === 'thunbolt-bin') {
			return;
		}

		foreach ($extra['configs'] as $config) {
			if (file_exists($this->getInstallPath($package) . '/' . $config)) {
				$this->jsonData['configs'] = $this->getInstallPath($package) . '/' . $config;
			} else {
				$this->io->write("<warning>Skipped installation of '$config' for package " . $package->getName() . " file not found in package.</warning>");
			}
		}
	}

	/**
	 * extra: {
	 * 		"configs": ["dir/config.neon"]
	 * }
	 * {@inheritDoc}
	 */
	protected function uninstallConfigs(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['configs']) || $package->getType() === 'thunbolt-bin') {
			return;
		}

		foreach ($extra['configs'] as $config) {
			if (($key = array_search($this->getInstallPath($package) . '/' . $config, $this->jsonData['configs'])) !== FALSE) {
				unset($this->jsonData['configs'][$key]);
			}
		}
	}

	/**
	 * extra: {
	 * 		"assets": {
	 *			"css": ["file.css"]
	 * 			"js": ["file.js"],
	 * 			"other": ["image.png"]
	 * 		}
	 * }
	 * {@inheritDoc}
	 */
	protected function uninstallAssets(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['assets']['css']) && !isset($extra['assets']['js']) && !isset($extra['assets']['other'])) {
			return;
		}

		$this->removeAssets($package, $extra['assets']);
	}

	/**
	 * extra: {
	 * 		"assets": {
	 *			"css": ["file.css"]
	 * 			"js": ["file.js"],
	 * 			"other": ["image.png"]
	 * 		}
	 * }
	 * {@inheritDoc}
	 */
	protected function removeAssets(PackageInterface $package, array $extra) {
		if (!isset($this->jsonData['assets'][$package->getName()]) || !is_array($this->jsonData['assets'][$package->getName()])) {
			return;
		}

		$target = self::PATHS['resourceDir'] . '/' . str_replace('/', '_', $package->getName());
		$this->filesystem->removeDirectoryPhp($target);

		unset($this->jsonData['assets']);
	}

	/**
	 * extra: {
	 * 		"assets": {
	 *			"css": ["file.css"]
	 * 			"js": ["file.js"],
	 * 			"other": ["image.png"]
	 * 		}
	 * }
	 * {@inheritDoc}
	 */
	protected function installAssets(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['assets']['css']) && !isset($extra['assets']['js'])) {
			return;
		}

		$this->copyAssets('css', $package, $extra['assets']);
		$this->copyAssets('js', $package, $extra['assets']);
		$this->copyAssets('other', $package, $extra['assets']);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function copyAssets($type, PackageInterface $package, array $extra) {
		if (!isset($extra[$type])) {
			return;
		}

		$this->jsonData['assets'][$package->getName()][$type] = [];

		foreach ((array) $extra[$type] as $row) {
			$rowPath = $this->getInstallPath($package) . '/' . $row;
			if (!file_exists($rowPath)) {
				$this->io->write("<warning>Skipped installation of '$row' for package " . $package->getName() . " file not found in package.</warning>");
				continue;
			}
			$target = self::PATHS['resourceDir'] . '/' . str_replace('/', '_', $package->getName()) . '/' . $type . '/' . basename($row);
			if (!file_exists($target)) {
				$this->filesystem->ensureDirectoryExists(dirname($target));
				copy($target, $rowPath);
			}
			$this->jsonData['assets'][$package->getName()][$type][] = $baseDir;
		}

	}

	/**
	 * {@inheritDoc}
	 */
	public function supports($packageType) {
		return isset($this->types[$packageType]);
	}

}
