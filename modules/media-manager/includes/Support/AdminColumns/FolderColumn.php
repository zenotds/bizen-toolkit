<?php

declare(strict_types=1);

namespace Bizen\MediaManager\Support\AdminColumns;

use AC;
use AC\Column\BaseColumnFactory;
use AC\FormatterCollection;
use AC\Setting\Config;

class FolderColumn extends BaseColumnFactory
{
    public function get_label(): string
    {
        return __('Folder', 'bizen-toolkit');
    }

    public function get_column_type(): string
    {
        return 'ac-filebird_folder';
    }

    protected function get_formatters(Config $config): FormatterCollection
    {
        $formatters = new FormatterCollection();
        $formatters->add(new FolderFormatter());

        return $formatters;
    }
}
