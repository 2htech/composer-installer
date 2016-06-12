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

	/** @var array */
	private $types = [
		"thunbolt-module" => "ins/modules",
		"thunbolt-core" => "app",
		"thunbolt-bin" => "bin",
		"thunbolt-component" => "ins/components",
		"thunbolt-package" => "ins/packages"
	];

	/** @var string */
	protected $resourceDir = 'ins/assets';

	/** @var string */
	protected $resourceFile;

	/** @var string */
	protected $templateLoader = 'bin/loaders/template.json';

	/** @var string */
	protected $binConfigs = 'bin/config.json';

	/** @var array */
	protected $jsonData = array();

	/** @var array */
	protected $templateLoaderData = array();

	/** @var string */
	protected $appDir = 'app';

	/** @var string */
	protected $wwwDir = 'www';

	/** @var string */
	protected $configFile;

	/**
	 * Initializes library installer.
	 *
	 * @param IOInterface $io
	 * @param Composer    $composer
	 * @param string      $type
	 * @param Filesystem  $filesystem
	 */
	public function __construct(IOInterface $io, Composer $composer, $type = 'library', Filesystem $filesystem = null) {
		parent::__construct($io, $composer, $type, $filesystem);

		$this->resourceFile = $this->appDir . '/config/composer.json';
	}

	/**
	 * {@inheritDoc}
	 */
	public function getInstallPath(PackageInterface $package) {
		if (in_array($package->getType(), array('webchemistry-core', 'webchemistry-bin'))) {
			return $this->types[$package->getType()];
		}

		return $this->types[$package->getType()]. '/' . $package->getPrettyName();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize() {
		$this->filesystem->ensureDirectoryExists($this->appDir);
		$this->filesystem->ensureDirectoryExists($this->wwwDir . '/' . $this->resourceDir);
		$this->filesystem->ensureDirectoryExists($this->appDir . '/config');

		$this->jsonData = file_exists($this->resourceFile) ? json_decode(file_get_contents($this->resourceFile), TRUE) : array();
		if (!array_key_exists('assets', $this->jsonData)) {
			$this->jsonData['assets'] = [];
		}
		if (!array_key_exists('configs', $this->jsonData)) {
			$this->jsonData['configs'] = [];
		}

		$this->templateLoaderData = file_exists($this->templateLoader) ? json_decode(file_get_contents($this->templateLoader), TRUE) : array();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function finish() {
		array_unique($this->jsonData['assets']);
		file_put_contents($this->resourceFile, json_encode($this->jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		file_put_contents($this->templateLoader, json_decode($this->templateLoader, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
	 * {@inheritDoc}
	 */
	protected function installTemplateLoader(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['configs']) || $package->getType() !== 'webchemistry-bin') {
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
	 * {@inheritDoc}
	 */
	protected function uninstallTemplateLoader(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['configs']) || $package->getType() !== 'webchemistry-bin') {
			return;
		}

		foreach ($extra['configs'] as $config) {
			if (($key = array_search($this->getInstallPath($package) . '/' . $config, $this->jsonData['configs'])) !== FALSE) {
				unset($this->templateLoaderData['configs'][$key]);
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function installConfigs(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['configs']) || $package->getType() === 'webchemistry-bin') {
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
	 * {@inheritDoc}
	 */
	protected function uninstallConfigs(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['configs']) || $package->getType() === 'webchemistry-bin') {
			return;
		}

		foreach ($extra['configs'] as $config) {
			if (($key = array_search($this->getInstallPath($package) . '/' . $config, $this->jsonData['configs'])) !== FALSE) {
				unset($this->jsonData['configs'][$key]);
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function uninstallAssets(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['assets']['css']) && !isset($extra['assets']['js'])) {
			return;
		}

		$this->removeAssets($package, $extra['assets']);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function removeAssets(PackageInterface $package, array $extra) {
		if (!isset($this->jsonData['assets'][$package->getName()]) || !is_array($this->jsonData['assets'][$package->getName()])) {
			return;
		}

		$target = $this->wwwDir . '/' . $this->resourceDir . '/' . str_replace('/', '_', $package->getName());
		$this->filesystem->removeDirectoryPhp($target);

		unset($this->jsonData['assets']);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function installAssets(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['assets']['css']) && !isset($extra['assets']['js'])) {
			return;
		}

		$this->copyAssets('css', $package, $extra['assets']);
		$this->copyAssets('js', $package, $extra['assets']);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function copyAssets($type, PackageInterface $package, array $extra) {
		if (!isset($extra[$type])) {
			return;
		}

		$this->jsonData['assets'][$package->getName()][$type] = [];

		foreach ((array) $extra[$type] as $compiled => $assets) {
			foreach ((array) $assets as $row) {
				$rowPath = $this->getInstallPath($package) . '/' . $row;
				if (!file_exists($rowPath)) {
					$this->io->write("<warning>Skipped installation of '$row' for package " . $package->getName() . " file not found in package.</warning>");
					continue;
				}
				$baseDir = $this->resourceDir . '/' . str_replace('/', '_', $package->getName()) . '/' . $type . '/' . basename($row);
				$target = $this->wwwDir . '/' . $baseDir;
				if (!file_exists($target)) {
					$this->filesystem->ensureDirectoryExists(dirname($target));
					file_put_contents($target, file_get_contents($rowPath));
				}
				$this->jsonData['assets'][$package->getName()][$type][$compiled][] = $baseDir;
			}
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports($packageType) {
		return isset($this->types[$packageType]);
	}

}
