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
		'insDir' => 'mod',
		'cfgDir' => 'app/config',
		'modDir' => 'app/modules',
		'resourceFile' => 'app/config/composer.json',
		'resourceDir' => 'www/mod-assets',
		'binConfigs' => 'bin/config.json',
		'templateLoader' => 'bin/loaders/template.json'
	];

	/** @var array */
	private $types = array(
		"thunbolt-module" => "mod/modules",
		"thunbolt-bin" => "bin",
		"thunbolt-component" => "mod/components",
		"thunbolt-package" => "mod/packages",
		"thunbolt-command" => "mod/commands"
	);

	/** @var array */
	protected $jsonData = array();

	/**
	 * {@inheritDoc}
	 */
	public function getInstallPath(PackageInterface $package) {
		$type = $package->getType();
		$extra = $package->getExtra();

		if ($type === 'thunbolt-bin') {
			return $this->types[$type] . '/' . (isset($extra['packageName']) ? $extra['packageName'] : $package->getPrettyName());
		}
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

		$this->jsonData = file_exists(self::PATHS['resourceFile']) ? json_decode(file_get_contents(self::PATHS['resourceFile']), TRUE) : array();
		if (!array_key_exists('configs', $this->jsonData)) {
			$this->jsonData['configs'] = [];
		}
		if (!array_key_exists('assets', $this->jsonData)) {
			$this->jsonData['assets'] = [];
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function finish() {
		array_unique($this->jsonData['assets']);
		array_values($this->jsonData['assets']); // Reset keys
		array_unique($this->jsonData['configs']);
		array_values($this->jsonData['configs']); // Reset keys

		file_put_contents(self::PATHS['resourceFile'], json_encode($this->jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * {@inheritDoc}
	 */
	public function install(InstalledRepositoryInterface $repo, PackageInterface $package) {
		parent::install($repo, $package);

		$this->initialize();
		$this->installBin($package);
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
			$this->uninstallBin($initial);
			$this->installBin($target);
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
			$this->uninstallBin($package);
			$this->uninstallConfigs($package);
			$this->uninstallAssets($package);
			$this->finish();
		}

		parent::uninstall($repo, $package);
	}

	/**
	 * Only for "type": "thunbolt-bin"
	 * extra: {
	 * 		"config": ["config.json"],
	 * 		"bin": ["dir/bin.bat"]
	 * }
	 * {@inheritDoc}
	 */
	protected function installBin(PackageInterface $package) {
		$extra = $package->getExtra();
		if ($package->getType() !== 'thunbolt-bin') {
			return;
		}

		if (isset($extra['config'])) {
			foreach ((array) $extra['config'] as $config) {
				$this->filesystem->ensureDirectoryExists(self::PATHS['binDir'] . '/config');
				if (!file_exists($this->getInstallPath($package) . '/' . $config)) {
					$this->io->write("<warning>Skipped installation of '$config' for package " . $package->getName() . " file not found in package.</warning>");
				}

				if (!copy($this->getInstallPath($package) . '/' . $config, self::PATHS['binDir'] . '/config/' . basename($config))) {
					$this->io->write("<warning>Skipped installation of '$config' for package " . $package->getName() . " file cannot copy to target directory.</warning>");
				}
			}
		}
		if (isset($extra['bin'])) {
			foreach ((array) $extra['bin'] as $bin) {
				if (!file_exists($this->getInstallPath($package) . '/' . $bin)) {
					$this->io->write("<warning>Skipped installation of '$bin' for package " . $package->getName() . " file not found in package.</warning>");
				}

				if (!copy($this->getInstallPath($package) . '/' . $bin, self::PATHS['binDir'] . '/' . basename($bin))) {
					$this->io->write("<warning>Skipped installation of '$bin' for package " . $package->getName() . " file cannot copy to target directory.</warning>");
				}
			}
		}
	}

	/**
	 * Only for "type": "thunbolt-bin"
	 * extra: {
	 * 		"config": ["dir/config.json"],
	 * 		"bin": ["dir/bin.bat"]
	 * }
	 * {@inheritDoc}
	 */
	protected function uninstallBin(PackageInterface $package) {
		$extra = $package->getExtra();
		if ($package->getType() !== 'thunbolt-bin') {
			return;
		}

		if (isset($extra['config'])) {
			foreach ($extra['config'] as $config) {
				if (file_exists($path = self::PATHS['binDir'] . '/' . basename($config))) {
					$this->filesystem->unlink($path);
					if ($this->filesystem->isDirEmpty(dirname($path))) {
						$this->filesystem->removeDirectory(dirname($path));
					}
				}
			}
		}

		if (isset($extra['bin'])) {
			foreach ($extra['bin'] as $bin) {
				if (file_exists($path = self::PATHS['binDir'] . '/' . $bin)) {
					$this->filesystem->unlink($path);
				}
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
		$target = self::PATHS['resourceDir'] . '/' . str_replace('/', '_', $package->getName());
		$this->filesystem->removeDirectoryPhp($target);

		unset($this->jsonData['assets']);
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
			if (!file_exists($rowPath)) {
				$this->io->write("<warning>Skipped installation of '$row' for package " . $package->getName() . " file not found in package.</warning>");
				continue;
			}
			$target = self::PATHS['resourceDir'] . '/' . str_replace('/', '_', $package->getName()) . '/' . basename($row);
			if (!file_exists($target)) {
				$this->filesystem->ensureDirectoryExists(dirname($target));
				copy($target, $rowPath);
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
