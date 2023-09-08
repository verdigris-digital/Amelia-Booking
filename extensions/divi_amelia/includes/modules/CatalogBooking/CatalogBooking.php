<?php

use AmeliaBooking\Infrastructure\WP\GutenbergBlock\GutenbergBlock;
use AmeliaBooking\Infrastructure\WP\Translations\BackendStrings;

class DIVI_CatalogBooking extends ET_Builder_Module
{

    public $slug       = 'divi_catalog_booking';
    public $vb_support = 'on';

    public $type = array();

    private $categories       = array();
    private $services         = array();
    private $packages         = array();
    private $employees        = array();
    private $catalog          = array();
    private $locations        = array();
    private $showPackages     = true;
    private $trigger_types    = array();

    protected $module_credits = array(
        'module_uri' => '',
        'author'     => '',
        'author_uri' => '',
    );

    public function init()
    {
        $this->name = esc_html__(BackendStrings::getWordPressStrings()['catalog_booking_divi'], 'divi-divi_amelia');

        $this->type['0']        = BackendStrings::getWordPressStrings()['show_all'];
        $this->type['services'] = BackendStrings::getCommonStrings()['services'];
        $this->type['packages'] = BackendStrings::getCommonStrings()['packages'];


        if (!is_admin()) {
            return;
        }

        $this->trigger_types = [
            'id' => BackendStrings::getWordPressStrings()['trigger_type_id'],
            'class' => BackendStrings::getWordPressStrings()['trigger_type_class']
        ];

        $data = GutenbergBlock::getEntitiesData()['data'];

        $this->showPackages = !empty($data['packages']);

        $this->catalog['0']        = BackendStrings::getWordPressStrings()['show_catalog'];
        $this->catalog['category'] = BackendStrings::getWordPressStrings()['show_category'];
        $this->catalog['service']  = BackendStrings::getWordPressStrings()['show_service'];
        if ($this->showPackages) {
            $this->catalog['package'] = BackendStrings::getWordPressStrings()['show_package'];
        }


        $this->categories['0'] = BackendStrings::getWordPressStrings()['choose_category'];
        foreach ($data['categories'] as $category) {
            $this->categories[$category['id']] = $category['name'] . ' (id: ' . $category['id'] . ')';
        }

        $this->services['0'] = BackendStrings::getWordPressStrings()['choose_service'];
        foreach ($data['servicesList'] as $service) {
            if ($service) {
                $this->services[$service['id']] = $service['name']. ' (id: ' . $service['id'] . ')';
            }
        }

        $this->packages['0'] = BackendStrings::getWordPressStrings()['choose_package'];
        foreach ($data['packages'] as $package) {
            $this->packages[$package['id']] = $package['name']. ' (id: ' . $package['id'] . ')';
        }

        $this->employees['0'] = BackendStrings::getWordPressStrings()['show_all_employees'];
        foreach ($data['employees'] as $employee) {
            $this->employees[$employee['id']] = $employee['firstName'] . ' ' . $employee['lastName'] . ' (id: ' . $employee['id'] . ')';
        }
        $this->locations['0'] = BackendStrings::getWordPressStrings()['show_all_locations'];
        foreach ($data['locations'] as $location) {
            $this->locations[$location['id']] = $location['name'] . ' (id: ' . $location['id'] . ')';
        }
    }

    /**
     * Advanced Fields Config
     *
     * @return array
     */
    public function get_advanced_fields_config()
    {
        return array(
            'button' => false,
            'link_options' => false
        );
    }

    public function get_fields()
    {
        $array = array(
            'catalog' => array(
                'label'           => esc_html__(BackendStrings::getWordPressStrings()['select_catalog_view'], 'divi-divi_amelia'),
                'type'            => 'select',
                'options'         => $this->catalog,
                'toggle_slug'     => 'main_content',
                'option_category' => 'basic_option',
            ),
            'categories' => array(
                'label'           => esc_html__(BackendStrings::getWordPressStrings()['select_category'], 'divi-divi_amelia'),
                'type'            => 'select',
                'options'         => $this->categories,
                'toggle_slug'     => 'main_content',
                'option_category' => 'basic_option',
                'show_if'         => array(
                    'catalog' => 'category',
                ),
            ),
            'services' => array(
                'label'           => esc_html__(BackendStrings::getWordPressStrings()['select_service'], 'divi-divi_amelia'),
                'type'            => 'select',
                'options'         => $this->services,
                'toggle_slug'     => 'main_content',
                'option_category' => 'basic_option',
                'show_if'         => array(
                    'catalog' => 'service',
                ),
            ),
            'packages' => array(
                'label'           => esc_html__(BackendStrings::getWordPressStrings()['select_package'], 'divi-divi_amelia'),
                'type'            => 'select',
                'options'         => $this->packages,
                'toggle_slug'     => 'main_content',
                'option_category' => 'basic_option',
                'show_if'         => array(
                    'catalog' => 'package',
                ),
            ),
            'booking_params' => array(
                'label'           => esc_html__(BackendStrings::getWordPressStrings()['filter'], 'divi-divi_amelia'),
                'type'            => 'yes_no_button',
                'options' => array(
                    'on'  => esc_html__(BackendStrings::getCommonStrings()['yes'], 'divi-divi_amelia'),
                    'off' => esc_html__(BackendStrings::getCommonStrings()['no'], 'divi-divi_amelia'),
                ),
                'toggle_slug'     => 'main_content',
                'option_category' => 'basic_option',
            ),
            'skip_categories' => array(
                'label'           => esc_html__(BackendStrings::getWordPressStrings()['skip_categories'], 'divi-divi_amelia'),
                'type'            => 'yes_no_button',
                'options' => array(
                    'on'  => esc_html__(BackendStrings::getCommonStrings()['yes'], 'divi-divi_amelia'),
                    'off' => esc_html__(BackendStrings::getCommonStrings()['no'], 'divi-divi_amelia'),
                ),
                'toggle_slug'     => 'main_content',
                'option_category' => 'basic_option',
                'show_if'         => array(
                    'booking_params' => 'on',
                ),
            ),
            'employees' => array(
                'label'           => esc_html__(BackendStrings::getWordPressStrings()['select_employee'], 'divi-divi_amelia'),
                'type'            => 'select',
                'options'         => $this->employees,
                'toggle_slug'     => 'main_content',
                'option_category' => 'basic_option',
                'show_if'         => array(
                    'booking_params' => 'on',
                ),
            ),
            'locations' => array(
                'label'           => esc_html__(BackendStrings::getWordPressStrings()['select_location'], 'divi-divi_amelia'),
                'type'            => 'select',
                'options'         => $this->locations,
                'toggle_slug'     => 'main_content',
                'option_category' => 'basic_option',
                'show_if'         => array(
                    'booking_params' => 'on',
                ),
            ),
        );

        if ($this->showPackages) {
            $array['type'] = array(
                'label'           => esc_html__(BackendStrings::getWordPressStrings()['show_all'], 'divi-divi_amelia'),
                'type'            => 'select',
                'options'         => $this->type,
                'toggle_slug'     => 'main_content',
                'option_category' => 'basic_option',
                'show_if'         => array(
                    'booking_params' => 'on'
                ));
        }

        $array['trigger'] = array(
            'label'           => esc_html__(BackendStrings::getWordPressStrings()['manually_loading'], 'divi-divi_amelia'),
            'type'            => 'text',
            'toggle_slug'     => 'main_content',
            'option_category' => 'basic_option',
            'description'     => BackendStrings::getWordPressStrings()['manually_loading_description'],
        );


        $array['trigger_type'] = array(
            'label'           => esc_html__(BackendStrings::getWordPressStrings()['trigger_type'], 'divi-divi_amelia'),
            'type'            => 'select',
            'options'         => $this->trigger_types,
            'toggle_slug'     => 'main_content',
            'option_category' => 'basic_option',
        );

        return $array;
    }

    public function checkValues($val)
    {
        if ($val !== null) {
            return !is_numeric($val) ? (strpos($val, 'id:') ?  substr(explode('id: ', $val)[1], 0, -1) : '0') : $val;
        }
        return '0';
    }

    public function render($attrs, $content = null, $render_slug = null)
    {
        $preselect =  $this->props['booking_params'];
        $shortcode = '[ameliacatalogbooking';
        $trigger   = $this->props['trigger'];
        $trigger_type = $this->props['trigger_type'];
        $showAll   = isset($this->props['type']) ? $this->props['type'] : null;
        if ($showAll !== null && $showAll !== '' && $showAll !== '0') {
            $shortcode .= ' show='.$showAll;
        }
        if ($trigger !== null && $trigger !== '') {
            $shortcode .= ' trigger='.$trigger;
        }
        if (!empty($trigger) && !empty($trigger_type)) {
            $shortcode .= ' trigger_type='.$trigger_type;
        }
        $catalog = $this->props['catalog'];
        if ($catalog !== '0') {
            $category = $this->checkValues($this->props['categories']);
            $service  = $this->checkValues($this->props['services']);
            $package1 = $this->checkValues($this->props['packages']);

            if ($category !== '0' && $catalog === 'category') {
                $shortcode .= ' category=' . $category;
            } else if ($service !== '0' && $catalog === 'service') {
                $shortcode .= ' service=' . $service;
            } else if ($package1 !== '0' && $catalog === 'package') {
                $shortcode .= ' package=' . $package1;
            }
        }
        if ($preselect === 'on') {
            $employee = $this->checkValues($this->props['employees']);
            $location = $this->checkValues($this->props['locations']);

            if ($employee !== '0') {
                $shortcode .= ' employee=' . $employee;
            }
            if ($location !== '0') {
                $shortcode .= ' location=' . $location;
            }

            $skipCategories = $this->props['skip_categories'];
            if ($skipCategories === 'on') {
                $shortcode .= ' categories_hidden=1';
            }
        }
        $shortcode .= ']';

        return do_shortcode($shortcode);
    }
}

new DIVI_CatalogBooking;
