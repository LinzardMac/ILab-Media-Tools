<?php

if (!defined('ABSPATH')) { header('Location: /'); die; }

require_once(ILAB_VENDOR_DIR.'/autoload.php');
require_once(ILAB_CLASSES_DIR.'/ilab-media-tool-base.php');

class ILabMediaS3Tool extends ILabMediaToolBase {

    private $key;
    private $secret;
    private $region;
    private $bucket;

    private $docCdn;

    public function __construct($toolName, $toolInfo, $toolManager)
    {
        parent::__construct($toolName, $toolInfo, $toolManager);

        $this->bucket=get_option('ilab-media-s3-bucket', getenv('ILAB_AWS_S3_BUCKET'));
        $this->key = get_option('ilab-media-s3-access-key', getenv('ILAB_AWS_S3_ACCESS_KEY'));
        $this->secret = get_option('ilab-media-s3-secret', getenv('ILAB_AWS_S3_ACCESS_SECRET'));
        $this->region=get_option('ilab-media-s3-region', getenv('ILAB_AWS_S3_REGION'));
        $this->docCdn = get_option('ilab-doc-s3-cdn-base');
    }

    public function enabled()
    {
        $enabled=parent::enabled();

        if ($enabled)
        {
            if (!($this->key && $this->secret && $this->bucket && $this->region))
            {
                $this->displayAdminNotice('error',"To start using S3, you will need to <a href='admin.php?page={$this->options_page}'>supply your AWS credentials.</a>.");
                return false;
            }

            if (!get_option('ilab-media-s3-cdn-base', getenv('ILAB_AWS_S3_CDN_BASE')))
            {
                $this->displayAdminNotice('error',"To start using S3, you will need to <a href='admin.php?page={$this->options_page}'>set up CDN information.</a>.");
                return false;
            }
        }

        return $enabled;
    }

    public function setup()
    {
        if ($this->enabled())
        {
            add_filter('wp_update_attachment_metadata', [$this, 'updateAttachmentMetadata'], 1000, 2);
            add_filter('delete_attachment', [$this, 'deleteAttachment'], 1000);
            add_filter('wp_handle_upload', [$this, 'handleUpload'], 10000);
        }

        if (get_option('ilab-media-s3-cdn-base', getenv('ILAB_AWS_S3_CDN_BASE')))
            add_filter('wp_get_attachment_url', [$this, 'getAttachmentURL'], 1000, 2 );
    }


    private function s3Client($insure_bucket=false)
    {
        if (!$this->enabled())
            return null;

        $s3=new Aws\S3\S3Client([
                                    'version' => 'latest',
                                    'region'  => $this->region,
                                    'credentials' => [
                                        'key'    => $this->key,
                                        'secret' => $this->secret
                                    ]
                                ]);

        if ($insure_bucket && (!$s3->doesBucketExist($this->bucket)))
            return null;

        return $s3;
    }

    /**
     * Filter for when attachments are updated
     *
     * @param $data
     * @param $id
     * @return mixed
     */
    public function updateAttachmentMetadata($data,$id)
    {
        if (!$data)
            return $data;

        $s3=$this->s3Client(true);
        if ($s3)
        {
            $upload_info=wp_upload_dir();
            $upload_path=$upload_info['basedir'];
            $path_base=pathinfo($data['file'])['dirname'];

            $data=$this->process_file($s3,$upload_path,$data['file'],$data);

            foreach($data['sizes'] as $key => $size)
            {
                if (!is_array($size))
                    continue;

                $file=$path_base.'/'.$size['file'];
                $data['sizes'][$key]=$this->process_file($s3,$upload_path,$file,$size);
            }
        }

        return $data;
    }

    public function handleUpload($upload, $context='upload') {
        if (!$this->docCdn)
            return $upload;

        if (!isset($upload['file']))
            return $upload;
        
        if (file_is_displayable_image($upload['file']))
            return $upload;
        
        $s3=$this->s3Client(true);
        if ($s3)
        {
            $pi = pathinfo($upload['file']);

            $upload_info=wp_upload_dir();
            $upload_path=$upload_info['basedir'];

            $file = trim(str_replace($upload_path,'',$pi['dirname']),'/').'/'.$pi['basename'];

            $upload = $this->process_file($s3, $upload_path, $file, $upload);
            if (isset($upload['s3'])) {
                $upload['url'] = trim($this->docCdn, '/').'/'.$file;
            }
        }
        
        return $upload;
    }

    private function process_file($s3,$upload_path,$filename,$data)
    {
        if (!file_exists($upload_path.'/'.$filename))
            return $data;

        if (isset($data['s3']))
        {
            $key = $data['s3']['key'];

            if ($key == $filename)
                return $data;

            $this->delete_file($s3,$key);
        }

        $file=fopen($upload_path.'/'.$filename,'r');
        try
        {
            $s3->upload($this->bucket,$filename,$file,'public-read');
            $data['s3']=[
              'bucket'=>$this->bucket,
              'key'=>$filename
            ];
        }
        catch (\Aws\Exception\AwsException $ex)
        {
            error_log($ex->getMessage());
        }

        return $data;
    }

    /**
     * Filters for when attachments are deleted
     * @param $id
     * @return mixed
     */
    public function deleteAttachment($id)
    {
        $s3=$this->s3Client(true);
        if ($s3)
        {
            $data=wp_get_attachment_metadata($id);

            $path_base=pathinfo($data['file'])['dirname'];

            $this->delete_file($s3,$data['file']);

            if (isset($data['sizes'])) {
                foreach($data['sizes'] as $key => $size) {
                    $file=$path_base.'/'.$size['file'];
                    try {
                        $this->delete_file($s3,$file);
                    } catch (\Exception $ex) {
                        error_log($ex->getMessage());
                    }
                }
            }
        }

        return $id;
    }

    private function delete_file($s3,$file)
    {
        try
        {
            if ($s3->doesObjectExist($this->bucket,$file))
            {
                $s3->deleteObject(array(
                                      'Bucket' => $this->bucket,
                                      'Key'    => $file
                                  ));
            }
        }
        catch (\Aws\Exception\AwsException $ex)
        {
            error_log($ex->getMessage());
        }
    }

    public function getAttachmentURL($url, $post_id)
    {
        $meta=wp_get_attachment_metadata($post_id);
        if (isset($meta['s3']))
        {
            $cdn=get_option('ilab-media-s3-cdn-base', getenv('ILAB_AWS_S3_CDN_BASE'));
            return $cdn.'/'.$meta['file'];
        }
        else if (isset($meta['amazonS3_info']))
        {
            $cdn=get_option('ilab-media-s3-cdn-base', getenv('ILAB_AWS_S3_CDN_BASE'));
            return $cdn.'/'.$meta['file'];
        }
        else if (!$meta) {
            $post = \WP_Post::get_instance($post_id);
            if (strpos($post->guid, $this->docCdn) === 0)
                return $post->guid;
        }

        return $url;
    }
}