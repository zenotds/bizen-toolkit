<?php

declare(strict_types=1);

namespace Bizen\MediaManager\Support\AdminColumns;

use AC\Setting\Formatter;
use AC\Type\Value;

use Bizen\MediaManager\Model\Folder as FolderModel;

class FolderFormatter implements Formatter
{
    private $folders = array();
    private $cached  = array();

    public function __construct() {
        $this->folders = FolderModel::allFolders( 'id,name,parent', null, null, null, OBJECT_K );
    }
    /**
     * Format the value to display in the column
     * 
     * @param Value $value
     * @return Value
     */
    public function format(Value $value)
    {
        $id = $value->get_id();
        $current_folder_id   = $this->get_current_folder_id( $id );
        $current_folder_path = $this->get_folder_path( $current_folder_id );

        if ( ! isset( $this->folders[ $current_folder_id ] ) ) {
            return $value->with_value('');
        }

        $formatted_string = sprintf( '<a data-id="%1$d" href="#" title="%2$s">%3$s</a>', $current_folder_id, $current_folder_path, $this->folders[ $current_folder_id ]->name );
        return $value->with_value($formatted_string);
    }
    public function get_current_folder_id( $post_id ) {
        if ( isset( $_GET['fbv'] ) && intval( $_GET['fbv'] ) > 0 ) {
            return intval( $_GET['fbv'] );
        }

        return FolderModel::getFolderIdFromPostId( $post_id ) ?? 0;
    }

    public function get_folder_path( $current_folder_id ) {
        if ( isset( $this->cached[ $current_folder_id ] ) ) {
            return $this->cached[ $current_folder_id ];
        }

        $folder_path     = array();
        $path            = '';
        $folder_from_map = $this->folders[ $current_folder_id ] ?? 0;

        while ( ! is_numeric( $folder_from_map ) ) {
            array_unshift( $folder_path, $folder_from_map->id );
            $folder_from_map = $this->folders[ $folder_from_map->parent ] ?? 0;
        }

        foreach ( $folder_path as $value ) {
            if ( $value !== end( $folder_path ) ) {
                $path .= $this->folders[ $value ]->name . ' / ';
            } else {
                $path .= $this->folders[ $value ]->name;
            }
        }

        $this->cached[ $current_folder_id ] = $path;

        return $path;
    }
}