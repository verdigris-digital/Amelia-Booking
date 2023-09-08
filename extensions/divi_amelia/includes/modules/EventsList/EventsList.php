<?php

use AmeliaBooking\Infrastructure\WP\GutenbergBlock\GutenbergBlock;
use AmeliaBooking\Infrastructure\WP\Translations\BackendStrings;

class DIVI_EventsList extends ET_Builder_Module
{

    public $slug       = 'divi_events_list_booking';
    public $vb_support = 'on';

    private $events = array();
    private $tags   = array();


    protected $module_credits = array(
        'module_uri' => '',
        'author'     => '',
        'author_uri' => '',
    );

    public function init()
    {
        $this->name = esc_html__(BackendStrings::getWordPressStrings()['events_list_booking_divi'], 'divi-divi_amelia');

        if (!is_admin()) {
            return;
        }

        $data = GutenbergBlock::getEntitiesData()['data'];

        $this->events['0'] = BackendStrings::getWordPressStrings()['show_all_events'];
        foreach ($data['events'] as $event) {
            $this->events[$event['id']] = $event['name'] . ' (id: ' . $event['id'] . ') - ' . $event['formattedPeriodStart'];
        }
        $this->tags['0'] = BackendStrings::getWordPressStrings()['show_all_tags'];
        foreach ($data['tags'] as $tag) {
            $this->tags[$tag['name']] = $tag['name'] . ' (id: ' . $tag['id'] . ')';
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
        return array(
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
            'events' => array(
                'label'           => esc_html__(BackendStrings::getWordPressStrings()['select_event'], 'divi-divi_amelia'),
                'type'            => 'select',
                'options'         => $this->events,
                'toggle_slug'     => 'main_content',
                'option_category' => 'basic_option',
                'show_if'         => array(
                    'booking_params' => 'on',
                ),
            ),
            'tags' => array(
                'label'           => esc_html__(BackendStrings::getWordPressStrings()['select_tag'], 'divi-divi_amelia'),
                'type'            => 'select',
                'toggle_slug'     => 'main_content',
                'options'         => $this->tags,
                'option_category' => 'basic_option',
                'show_if'         => array(
                    'booking_params' => 'on',
                ),
            ),
            'recurring' => array(
                'label'           => esc_html__(BackendStrings::getWordPressStrings()['recurring_event'], 'divi-divi_amelia'),
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
            'trigger' => array(
                'label'           => esc_html__(BackendStrings::getWordPressStrings()['manually_loading'], 'divi-divi_amelia'),
                'type'            => 'text',
                'toggle_slug'     => 'main_content',
                'option_category' => 'basic_option',
                'description'     => BackendStrings::getWordPressStrings()['manually_loading_description'],
            ),
        );
    }

    public function checkValues($val)
    {
        if ($val !== null) {
            $matches = [];
            $id      = preg_match('/id: \d+\)/', $val, $matches);
            return !is_numeric($val) ? ($id && count($matches) ? substr($matches[0], 4, -1) : '0') : $val;
        }
        return '0';
    }

    public function render($attrs, $content = null, $render_slug = null)
    {
        $preselect =  $this->props['booking_params'];
        $shortcode = '[ameliaeventslistbooking';
        $trigger   = $this->props['trigger'];
        if ($trigger !== null && $trigger !== '') {
            $shortcode .= ' trigger='.$trigger;
        }
        if ($preselect === 'on') {
            $event = $this->checkValues($this->props['events']);
            $tag   = $this->props['tags'];
            if ($event !== '0') {
                $shortcode .= ' event=' . $event;
            }
            if ($tag !== null && $tag !== '' && $tag !== '0') {
                $shortcode .= " tag='" . $tag . "'";
            }
            $recurring = $this->props['recurring'];
            if ($recurring === 'on') {
                $shortcode .= ' recurring=1';
            }
        }
        $shortcode .= ']';

        return do_shortcode($shortcode);
    }
}

new DIVI_EventsList;
