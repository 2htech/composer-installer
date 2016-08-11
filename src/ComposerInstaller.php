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
		'wwwDir' => 'www',
		'modDir' => 'mod',
		'resourceDir' => 'www/mod-assets',
		'resourceFile' => 'mod/composer.json',
	];

	/** @var array */
	private $types = array(
		"thunbolt-module" => "mod/modules",
		"thunbolt-component" => "mod/components",
		"thunbolt-package" => "mod/packages"
	);

	/** @var array */
	protected $jsonData = array();

	/**
	 * {@inheritDoc}
	 */
	public function getInstallPath(PackageInterface $package) {
		return $this->types[$package->getType()]. '/' . $package->getPrettyName();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function initialize() {
		$this->filesystem->ensureDirectoryExists(self::PATHS['modDir']);
		$this->filesystem->ensureDirectoryExists(self::PATHS['resourceDir']);

		$this->jsonData = file_exists(self::PATHS['resourceFile']) ? json_decode(file_get_contents(self::PATHS['resourceFile']), TRUE) : array();
		if (!$this->jsonData) {
			$this->jsonData = [];
		}
		if (!array_key_exists('configs', $this->jsonData)) {
			$this->jsonData['configs'] = [];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function finish() {
		$this->jsonData['configs'] = array_unique($this->jsonData['configs']);
		$this->jsonData['configs'] = array_values($this->jsonData['configs']); // Reset keys

		file_put_contents(self::PATHS['resourceFile'], json_encode($this->jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * {@inheritDoc}
	 */
	public function install(InstalledRepositoryInterface $repo, PackageInterface $package) {
		parent::install($repo, $package);

		$this->initialize();
		$this->installConfigs($package);
		$this->uninstallAssets($package);
		$this->installAssets($package);
		$this->finish();
	}

	/**
	 * {@inheritDoc}
	 */
	public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target) {
		$this->initialize();
		if ($this->isInstalled($repo, $initial)) {
			$this->uninstallConfigs($initial);
			$this->uninstallAssets($initial);
		}

		parent::update($repo, $initial, $target);

		$this->installConfigs($target);
		$this->installAssets($target);
		$this->finish();
	}

	/**
	 * {@inheritDoc}
	 */
	public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package) {
		if ($this->isInstalled($repo, $package)) {
			$this->initialize();
			$this->uninstallConfigs($package);
			$this->uninstallAssets($package);
			$this->finish();
		}

		parent::uninstall($repo, $package);
	}

	/**
	 * extra: {
	 * 		"configs": ["dir/config.neon"]
	 * }
	 * {@inheritDoc}
	 */
	protected function installConfigs(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['configs'])) {
			return;
		}

		foreach ($extra['configs'] as $config) {
			if (file_exists($this->getInstallPath($package) . '/' . $config)) {
				$this->jsonData['configs'][] = $this->getInstallPath($package) . '/' . $config;
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
		if (!isset($extra['configs'])) {
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
	 * 		"assets": ["file.css", "file.js", "file.png"]
	 * }
	 * {@inheritDoc}
	 */
	protected function uninstallAssets(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['assets'])) {
			return;
		}

		$this->removeAssets($package, $extra['assets']);
	}

	/**
	 * extra: {
	 * 		"assets": ["file.css", "file.js", "file.png"]
	 * }
	 * {@inheritDoc}
	 */
	protected function removeAssets(PackageInterface $package, array $extra) {
		$target = self::PATHS['resourceDir'] . '/' . $package->getName();
		if (file_exists($target) && is_dir($target)) {
			$this->filesystem->removeDirectoryPhp($target);
		}
	}

	/**
	 * extra: {
	 * 		"assets": ["file.css", "file.js", "file.png"]
	 * }
	 * {@inheritDoc}
	 */
	protected function installAssets(PackageInterface $package) {
		$extra = $package->getExtra();
		if (!isset($extra['assets'])) {
			return;
		}

		foreach ((array) $extra['assets'] as $row) {
			$rowPath = $this->getInstallPath($package) . '/' . $row;
			if (strpos($rowPath, '*') !== FALSE) {
				foreach (glob($rowPath) as $file) {
					if (is_dir($file)) {
						$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($file), \RecursiveIteratorIterator::SELF_FIRST);
						$iterator->setMaxDepth(-1);

						foreach ($iterator as $item) {
							if (is_file($item)) {
								$this->moveToTarget($package, $item);
							}
						}

						continue;
					}
					$this->moveToTarget($package, $file);
				}
			} else {
				$this->moveToTarget($package, $row);
			}
		}
	}

	private function moveToTarget(PackageInterface $package, $absolutePath) {
		if (!file_exists($absolutePath)) {
			$this->io->write("<warning>Skipped installation of '$absolutePath' for package " . $package->getName() . " file not found in package.</warning>");
			return;
		}
		$target = self::PATHS['resourceDir'] . '/' . $package->getName() . '/' . ltrim(str_replace($this->getInstallPath($package), '', $absolutePath), '\\/');
		if (!file_exists($target)) {
			$this->filesystem->ensureDirectoryExists(dirname($target));
			copy($absolutePath, $target);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports($packageType) {
		return isset($this->types[$packageType]);
	}

}
