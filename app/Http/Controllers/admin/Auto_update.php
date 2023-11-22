<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Exception;

class AutoUpdateController extends AdminController
{
    public function index()
    {
        $purchaseKey = trim(request()->input('purchase_key', false));
        $latestVersion = request()->input('latest_version');

        $url = config('constants.UPDATE_URL') . '?purchase_key=' . $purchaseKey;

        hooks()->doAction('beforePerformUpdate', $latestVersion);

        $tmpDir = storage_path('app/temp');

        try {
            $this->checkPermissions();
            $config = new \App\Services\Upgrade\Config(
                $purchaseKey,
                $latestVersion,
                $this->currentDbVersion,
                $url,
                $tmpDir,
                public_path()
            );

            if (request()->input('upgrade_function') === 'old') {
                $adapter = new \App\Services\Upgrade\CurlCoreUpgradeAdapter();
            } else {
                $adapter = new \App\Services\Upgrade\GuzzleCoreUpgradeAdapter();
            }

            $adapter->setConfig($config);
            $upgrade = new \App\Services\Upgrade\UpgradeCore($adapter);

            $upgrade->perform();
        } catch (Exception $e) {
            abort(400, $e->getMessage());
        }
    }
     /**
     * @throws Exception
     */
    private function checkPermissions()
    {
        $eMessage = 'The application could not write data into <strong>' . public_path();
        $eMessage .= '</strong> folder. Please give your web server user (<strong>' . get_current_user();
        $eMessage .= '</strong>) write permissions in <code>' . public_path() . '</code> folder:<br/><br/>';
        $eMessage .= '<pre style="background: #f0f0f0;padding: 15px;width: 50%;  margin-top:0px; border-radius: 4px;">sudo chgrp ' . get_current_user() . ' ';
        $eMessage .= public_path() . '<br/>sudo chmod ug+rwx ' . public_path() . '</pre>';

        $directoryIterator = new RecursiveDirectoryIterator(public_path());

        foreach (new RecursiveIteratorIterator($directoryIterator) as $file) {
            if ($file->isFile() && !$file->isWritable() && strpos($file->getPathname(), '.git') === false) {
                throw new Exception($eMessage);
            }
        }

        return true;
    }









}
