<?php

namespace DonationManager\organizations;
use function DonationManager\utilities\{get_alert};
use function DonationManager\helpers\{in_array_r};
use function DonationManager\templates\{render_template};

/**
 * Gets the default organization.
 *
 * @param      bool        $priority  The priority
 *
 * @return     array|bool  The default organization.
 */
function get_default_organization( $priority = false ) {
  if( WP_CLI && true === WP_CLI_TEST )
    \WP_CLI::line( '🔔 running get_default_organization()... ');

  $default_organization = get_field( 'default_organization', 'option' );
  if( WP_CLI && true === WP_CLI_TEST )
    \WP_CLI::line( '🔔 default_organization = ' . print_r( $default_organization, true ) );
  if( ! $default_organization ){
    if( WP_CLI && true === WP_CLI_TEST )
      \WP_CLI::line( '🚨 No default organization set! Check the settings page for the plugin.' );
    return false;
  }

  $default_trans_dept = get_field( 'default_transportation_department', 'option' );
  if( WP_CLI && true === WP_CLI_TEST )
    \WP_CLI::line( '🔔 default_trans_dept = ' . print_r( $default_trans_dept, true ) );
  if( ! $default_trans_dept ){
    if( WP_CLI && true === WP_CLI_TEST )
      \WP_CLI::line( '🚨 No default transportation department set! Check the settings page for the plugin.' );
    return false;
  }


  $button_texts = get_field( 'donation_button_text', 'option' );

  $organization = []; // initialize our return variable
  $organization['id'] = $default_organization->ID;
  $organization['trans_dept_id'] = $default_trans_dept->ID;

  if( $priority ){
    $organization['name'] = 'Expedited Pick Up Service';
    $organization['desc'] = get_alert([
      'title'         => null,
      'description'   => 'Choosing <strong>PRIORITY</strong> Pick Up will send your request to all of the <em>fee-based</em> pick up providers in our database. These providers will pick up "almost" <strong>ANYTHING</strong> you have for a fee, and their service provides <em>additional benefits</em> such as the removal of items from anywhere inside your property to be taken to a local non-profit, as well as the removal of junk and items local non-profits cannot accept.<br><br><em>In most cases your donation is still tax-deductible, and these organizations will respond in 24hrs or less. Check with whichever pick up provider you choose.</em>',
      'type'          => 'info',
    ]);
    $organization['button_text'] = $button_texts['priority'];
    $organization['priority_pickup'] = 1;
    $organization['alternate_donate_now_url'] = site_url( '/step-one/?oid=' . $default_organization->ID . '&tid=' . $default_trans_dept->ID . '&priority=1' );
  } else {
    $organization['name'] = $default_organization->post_title;
    $organization['desc'] = $default_organization->post_content;
    $organization['button_text'] = $button_texts['non_profit'];
    $organization['priority_pickup'] = 0;

    /**
     * 07/05/2022 (11:09) - I found 11 instances of an `alternate_donate_now_url`
     * meta key stored in the PMD production DB, but in each case, this meta row
     * had no value stored. Therefore, I don't think I've ever setup this meta.
     * Furthermore, I see no evidence of any GUI for it either. The following
     * code probably needs to be removed:
     */
    //$alternate_donate_now_url = get_post_meta( $default_organization->ID, 'alternate_donate_now_url', true );
    //$organization['alternate_donate_now_url'] = $alternate_donate_now_url;
  }

  return $organization;
}

/**
 * Returns emails for organizations within a specified radius of a given pickup code.
 *
 * @param array $args{
 *      @type int       $radius     Optional (defaults to 15 miles). Radius in miles to retrieve organization contacts.
 *      @type string    $pcode      Zip Code.
 *      @type int       $limit      Optional. Max number of contacts to return.
 *      @type bool      $priority   Optional. Query priority contacts.
 *      @type string    $fields     Optional. Specify fields to return (e.g. `store_name,zipcode,email_address`). Defaults to `email_address`.
 *      @type bool      $duplicates Optional. Should we return duplicate stores? Defaults to TRUE.
 * }
 * @return array Returns an array of contact emails.
 */
function get_orphaned_donation_contacts( $args ){
  global $wpdb;

  $orphaned_pickup_radius = get_field( 'orphaned_pickup_radius', 'option' );
  if( empty( $orphaned_pickup_radius ) || ! is_numeric( $orphaned_pickup_radius ) )
    $orphaned_pickup_radius = 15;

  $args = shortcode_atts([
      'radius'          => $orphaned_pickup_radius,
      'pcode'           => null,
      'limit'           => null,
      'priority'        => 0,
      'fields'          => 'email_address',
      'duplicates'      => true,
      'show_in_results' => null,
  ], $args );

  // Validate $args['priority'], ensuring it is only `0` or `1`.
  if( ! in_array( $args['priority'], array( 0, 1 ) ) )
      $args['priority'] = 0;

  $error = new \WP_Error();

  if( empty( $args['pcode'] ) )
      return $error->add( 'nopcode', 'No $pcode sent to get_orphaned_donation_contacts().' );

  // Get the Lat/Lon of our pcode
  $sql = 'SELECT ID,Latitude,Longitude FROM ' . $wpdb->prefix . 'dm_zipcodes WHERE ZIPCode="%s" ORDER BY CityName ASC LIMIT 1';
  $coordinates = $wpdb->get_results( $wpdb->prepare( $sql, $args['pcode'] ) );

  if( ! $coordinates )
      return $error->add( 'nocoordinates', 'No coordinates returned for `' . $args['pcode'] . '`.' );

  $lat = $coordinates[0]->Latitude;
  $lon = $coordinates[0]->Longitude;

  // Get all zipcodes within $args['radius'] miles of our pcode
  $sql = 'SELECT distinct(ZipCode) FROM ' . $wpdb->prefix . 'dm_zipcodes  WHERE (3958*3.1415926*sqrt((Latitude-' . $lat . ')*(Latitude-' . $lat . ') + cos(Latitude/57.29578)*cos(' . $lat . '/57.29578)*(Longitude-' . $lon . ')*(Longitude-' . $lon . '))/180) <= %d';
  $zipcodes = $wpdb->get_results( $wpdb->prepare( $sql, $args['radius'] ) );

  if( ! $zipcodes )
      return $error->add( 'nozipcodes', 'No zip codes returned for ' . $args['pcode'] . '.' );

  if( $zipcodes ){
      $zipcodes_array = array();
      foreach( $zipcodes as $zipcode ){
          $zipcodes_array[] = $zipcode->ZipCode;
      }
      $zipcodes = implode( ',', $zipcodes_array );
  }

  // Get all email addresses for contacts in our group of zipcodes
  $sql = 'SELECT ID,' . $args['fields'] . ' FROM ' . $wpdb->prefix . 'dm_contacts WHERE receive_emails=1 AND priority=' . $args['priority'] . ' AND zipcode IN (' . $zipcodes . ')';

  if( isset( $args['show_in_results'] ) && ! is_null( $args['show_in_results'] ) && in_array( $args['show_in_results'], [0,1] ) )
      $sql.= ' AND show_in_results=' . $args['show_in_results'];

  if( ! is_null( $args['limit'] ) && is_numeric( $args['limit'] ) )
      $sql.= ' LIMIT ' . $args['limit'];

  $contacts = $wpdb->get_results( $sql, ARRAY_A );

  if( ! $contacts )
      return $error->add( 'nocontacts', 'No contacts returned for `' . $args['pcode'] . '`.' );

  if( $contacts ){
      $contacts_array = [];
      foreach( $contacts as $key => $contact ){
          // Dirty Data: Remove &#194;&#160; from end of Store Names
          if( isset( $contact['store_name'] ) && 'store_name' == $key ){
              $contact['store_name'] = preg_replace( '/[[:^print:]]/', '', $contact['store_name'] );
              $contact['store_name'] = str_replace(chr(194).chr(160), '', $contact['store_name']);
          }
          // Prevents duplicates from the same store
          if( isset( $contact['store_name'] )
              && ( false == $args['duplicates'] )
              && in_array_r( $contact['store_name'], $contacts_array )
          )
              continue;

          // Generate by-pass link
          $default_organization = get_field( 'default_organization', 'option' );
          $default_trans_dept = get_field( 'default_transportation_department', 'option' );
          $siteurl = get_option( 'siteurl' );
          $contact['by-pass-link'] = $siteurl . '/step-one/?oid=' . $default_organization->ID . '&tid=' . $default_trans_dept->ID . '&priority=0&orphanid=' . $contact['ID'];

          if( isset( $contact['email_address'] ) && ! in_array_r( $contact['email_address'], $contacts_array ) ){
              $contacts_array[$contact['ID']] = ( 'email_address' == $args['fields'] )? $contact['email_address'] : $contact;
          }
      }
  }

  return $contacts_array;
}

/**
 * Retrieves all organizations for a given pickup code.
 *
 * @param      string  $pickup_code  The pickup code
 *
 * @return     array   The organizations.
 */
function get_organizations( $pickup_code ) {
  $args = [
    'post_type'   => 'trans_dept',
    'post_status' => 'publish',
    'tax_query'   => [
      [
        'taxonomy'  => 'pickup_code',
        'terms'     => $pickup_code,
        'field'     => 'slug'
      ]
    ],
  ];
  if( WP_CLI && true === WP_CLI_TEST && class_exists( 'WP_CLI' ) )
    \WP_CLI::line( '🔔 get_organizations() query args = ' . print_r( $args, true ) );

  $trans_depts = get_posts( $args );

  $organizations = [];

  if( $trans_depts ):
    /**
     * PRIORITY PICKUP SERVICE
     *
     * For organizations with meta `priority_pickup` == true, we
     * will also include our default pick up provider (i.e.
     * orphaned routing) in markets with just one pickup provider.
     * This will give donors a choice between paying for pick up
     * or using our orphaned routing process.
     */
    $priority_pickup = false;

    foreach( $trans_depts as $trans_dept ){
      //if( WP_CLI && true === WP_CLI_TEST && class_exists( 'WP_CLI' ) )
        //\WP_CLI::line( '🔔 $trans_dept = ' . print_r( $trans_dept, true ) );

      $org_id = get_post_meta( $trans_dept->ID, 'organization', true );
      $organization = get_post( $org_id );
      //if( WP_CLI && true === WP_CLI_TEST && class_exists( 'WP_CLI' ) )
        //\WP_CLI::line( '🔔 $organization = ' . print_r( $organization, true ) );

      $pickup_settings = get_field( 'pickup_settings', $organization->ID );
      $priority_pickup = false;
      $pause_pickups = false;
      if( $pickup_settings ){
        // 08/03/2022 (03:16) - not current stored
        // if( array_key_exists( 'alternate_donate_now_url', $pickup_settings ) )
        //   $alternate_donate_now_url = $pickup_settings['alternate_donate_now_url']; // 08/03/2022 (03:16) - not current stored
        $alternate_donate_now_url = null;

        if( array_key_exists( 'priority_pickup', $pickup_settings ) )
          $priority_pickup = ( $pickup_settings['priority_pickup'] === 'true' )? true : false ;

        if( array_key_exists( 'pause_pickups', $pickup_settings ) )
          $pause_pickups = ( $pickup_settings['pause_pickups'] === 'true' )? true : false ;
      }

      $edit_url = ( current_user_can( 'edit_posts' ) && isset( $org ) )? get_edit_post_link( $org->ID, 'link' ) : false ;

      if( $organization ){
        $organizations[] = [
          'id'                        => $organization->ID,
          'name'                      => $organization->post_title,
          'desc'                      => $organization->post_content,
          'trans_dept_id'             => $trans_dept->ID,
          'alternate_donate_now_url'  => $alternate_donate_now_url,
          'priority_pickup'           => $priority_pickup,
          'pause_pickups'             => $pause_pickups,
          'edit_url'                  => $edit_url,
        ];
      }

    }

    /**
     * We have only 1 org for this pickup_code, and it is a fee-based
     * priority pick up provider. So, we need to add our default pick
     * up provider (i.e. PMD) to the beginning of the list.
     */
    if( 1 == count( $organizations ) && true == $priority_pickup ){
        $default_org = get_default_organization();

        $orphaned_pickup_radius = get_field( 'orphaned_pickup_radius', 'option' );
        if( empty( $orphaned_pickup_radius ) || ! is_numeric( $orphaned_pickup_radius ) )
          $orphaned_pickup_radius = 15;

        // Get list of Orphaned Pick Up Providers
        $providers = get_orphaned_donation_contacts([
          'pcode' => $pickup_code,
          'radius' => $orphaned_pickup_radius,
          'priority' => 0,
          'fields' => 'store_name,email_address,zipcode,priority',
          'duplicates' => false, 'show_in_results' => 1
        ]);
        if( is_array( $providers ) && 0 < count( $providers ) )
            $default_org['providers'] = $providers;

        /* 07/05/2022 (11:15) - don't think we're using this:
        if( isset( $org['alternate_donate_now_url'] ) )
            $default_org['alternate_donate_now_url'] = $org['alternate_donate_now_url'];
        */
        array_unshift( $organizations, $default_org );
    }
  endif;

  return $organizations;
}

/**
 * Retrieves an array of meta_field data for an organization.
 *
 * TODO: Replace get_pickuplocations() and get_pickuptimes()
 * with this function.
 *
 * @link URL short description.
 * @global type $varname short description.
 *
 * @since 1.0.1
 *
 * @param int $org_id Organization ID.
 * @param string $taxonomy Name of the meta field we're retrieving.
 * @return array An array of arrays with each sub-array having a term ID and name.
 */
function get_organization_meta_array( $org_id, $taxonomy ){
  /**
   * We need the following terms from an Organization:
   *
   * - pickup_locations
   * - donation_options
   * - pickup_times
   * - screening_questions
   */

  $terms = wp_get_post_terms( $org_id, $taxonomy );

  $meta_array = array();
  $x = 1;
  if( $terms ){
      foreach( $terms as $term ){
          $pod = pods( $taxonomy );
          $pod->fetch( $term->term_id );
          $order = $pod->field( 'order' );
          $key = ( ! array_key_exists( $order, $meta_array ) )? $order : $x;
          $meta_array[$key] = array( 'id' => $term->term_id, 'name' => $term->name );
          $x++;
      }
  } else {
      $default_meta_ids = $this->get_default_setting_array( $taxonomy . 's' );
      if( is_array( $default_meta_ids ) && 0 < count( $default_meta_ids ) ) {
          foreach( $default_meta_ids as $meta_id ) {
              $term = get_term( $meta_id, $taxonomy );
              $pod = pods( $taxonomy );
              $pod->fetch( $meta_id );
              $order = $pod->field( 'order' );
              $key = ( ! array_key_exists( $order, $meta_array ) )? $order : $x;
              $meta_array[$key] = array( 'id' => $meta_id, 'name' => $term->name );
              $x++;
          }
      }
  }

  ksort( $meta_array );

  return $meta_array;
}

/**
 * Retrieves an org's pickup locations.
 */
function get_pickuplocations( $org_id ){
  $terms = wp_get_post_terms( $org_id, 'pickup_location' );
  if( 0 == count( $terms ) )
    $terms = get_field( 'default_options_default_pickup_locations', 'option' );

  $pickuplocations = [];
  if( 0 < count( $terms ) ){
    foreach( $terms as $term ){
      $pickuplocations[] = [
        'id'    => $term->term_id,
        'name'  => $term->name,
      ];
    }
  }
  //uber_log( '🔔 $pickuplocations = ' . print_r( $pickuplocations, true ) );

  return $pickuplocations;
}

/**
 * Retrieves an organization's picktup times.
 */
function get_pickuptimes( $org_id ){
  $terms = wp_get_post_terms( $org_id, 'pickup_time' );
  if( 0 == count( $terms ) )
    $terms = get_field( 'default_options_default_pickup_times', 'option' );

  $pickuptimes = [];
  if( 0 < count( $terms ) ){
    foreach( $terms as $term ){
      $pickuptimes[] = [
        'id'    => $term->term_id,
        'name'  => $term->name,
      ];
    }
  }
  //uber_log( '🔔 $pickuptimes = ' . print_r( $pickuptimes, true ) );

  return $pickuptimes;
}

/**
 * Returns priority organizations for a given $pickup_code.
 */
function get_priority_organizations( $pickup_code = null ){
  if( is_null( $pickup_code ) )
    return false;

  $args = array(
    'post_type' => 'trans_dept',
    'tax_query' => array(
      array(
        'taxonomy'  => 'pickup_code',
        'terms'     => $pickup_code,
        'field'     => 'slug'
      )
    )
  );
  $query = new \WP_Query( $args );

  $organizations = array();

  if( $query->have_posts() ){
    if( WP_CLI && true === WP_CLI_TEST && class_exists( 'WP_CLI' ) )
      \WP_CLI::line( '🔔 Looping through Transporation Departments: $query->posts...' );

    while( $query->have_posts() ): $query->the_post();
      global $post;
      setup_postdata( $post );
      $org_id = get_post_meta( $post->ID, 'organization', true );
      if( WP_CLI && true === WP_CLI_TEST && class_exists( 'WP_CLI' ) )
        \WP_CLI::line( '🔔 TRANS DEPT: ' . get_the_title() . ' (ORG: ' . get_the_title( $org_id ) . ', ID: ' . $org_id . ')' );

      // If no `organization` is set, $org_id is a `string`. Therefore
      // we must continue to the next post.
      // 08/16/2022 (12:29) - 👆👇 ???
      /*
      if( 'string' == gettype( $org_id ) )
        continue;
      /**/

      $pickup_settings = get_field( 'pickup_settings', $org_id );
      if( $pickup_settings ):
        // 08/03/2022 (03:16) - not current stored
        // if( array_key_exists( 'alternate_donate_now_url', $pickup_settings ) )
        //   $alternate_donate_now_url = $pickup_settings['alternate_donate_now_url']; // 08/03/2022 (03:16) - not current stored

        if( array_key_exists( 'priority_pickup', $pickup_settings ) )
          $priority_pickup = ( $pickup_settings['priority_pickup'] === 'true' )? true : false ;
      endif;

      if( $org_id && $priority_pickup ){
        $organizations[] = [
          'id' => $org_id,
          'name' => get_the_title( $org_id ),
          'desc' => get_the_content( null, false, $org_id ),
          'trans_dept_id' => $post->ID,
          /*'alternate_donate_now_url' => $alternate_donate_now_url,*/
          'priority_pickup' => 1,
        ];
      }
    endwhile;
    wp_reset_postdata();
    if( 0 == count( $organizations ) )
      $organizations[] = get_default_organization( true );
  } else {
      // No orgs for this zip, return PMD as priority so we can
      // use the Priority Orphan DB
      $default_org = get_default_organization( true );

      // Only provide the PRIORITY option for areas where there is
      // a priority provider in the contacts table.
      $contacts = get_orphaned_donation_contacts( array( 'pcode' => $pickup_code, 'limit' => 1, 'priority' => 1 ) );

      if( 0 < count( $contacts ) )
          $organizations[] = $default_org;
  }

  return $organizations;
}

/**
 * Returns Priority Pick Up HTML.
 */
function get_priority_pickup_links( $pickup_code = null, $note = null ){
    if( is_null( $pickup_code ) )
        return false;

    // Priority Donation Backlinks
    $priority_html = '';
    $priority_orgs = get_priority_organizations( $pickup_code );
    if( is_array( $priority_orgs ) ){
        foreach( $priority_orgs as $org ){
            // Setup button link
            if(
                isset( $org['alternate_donate_now_url'] )
                && filter_var( $org['alternate_donate_now_url'], FILTER_VALIDATE_URL )
            ){
                $link = $org['alternate_donate_now_url'];
            } else {
                $link = '/step-one/?oid=' . $org['id'] . '&tid=' . $org['trans_dept_id'] . '&priority=1';
            }

            $donation_button_text = get_field( 'donation_button_text', 'option' );
            $priority_button_text = ( array_key_exists( 'priority', $donation_button_text ) && ! empty( $donation_button_text['priority'] ) )?  $donation_button_text['priority'] : 'Click here for Priority Pick Up' ;

            $row = [
                'name' => $org['name'],
                'link' => $link,
                'button_text' => $priority_button_text,
                'css_classes' => ' priority',
                'desc' => '',
            ];
            if( stristr( $org['name'], 'College Hunks' ) )
                $row['desc'] = '<div style="text-align: center; font-size: 1.25em;"><div style="margin-bottom: 1em">OR</div>Call <a href="tel:888-912-4902">(888) 912-4902</a> for Priority Pick Up</div>';
            $rows[] = $row;
        }
        $hbs_vars = [
            'rows' => $rows,
        ];
        $priority_rows = render_template( 'form1.select-your-organization', $hbs_vars ); // 08/16/2022 (11:21) - originally `form1.select-your-organization.rows`

        /*
        if( is_null( $note ) )
            $note = 'Even though your items don\'t qualify for pick up, you can connect with our "fee based" priority pick up partner that will pick up items we can\'t use as well as any other items you would like to recycle or throw away:';

        $priority_html = '<div class="alert alert-warning"><h3 style="margin-top: 0;">Priority Pick Up Option</h3><p style="margin-bottom: 20px;">' . $note . '</p>' . $priority_rows . '</div>';
        /**/

        $priority_html = get_alert([
          'type' => 'warning',
          'description' => '<p>If you need a priority/fee-based pick up option, consider choosing our local priority partner:</p>' . $priority_rows
        ]);
    }

    return $priority_html;
}

/**
 * Retrieves an organization's screening questions. If none are assigned, returns the default questions.
 */
function get_screening_questions( $org_id = null ) {
  $screening_questions = [];

  if( ! is_null( $org_id ) )
    $terms = wp_get_post_terms( $org_id, 'screening_question' );

  if( ! $terms ){
    $terms = [];
    $default_options = get_field( 'default_options', 'option' );

    $default_question_ids = $default_options['default_screening_questions'];
    if( WP_CLI && true === WP_CLI_TEST && class_exists( 'WP_CLI' ) )
      \WP_CLI::line( '🔔 get_screening_questions() $default_question_ids = ' . print_r( $default_question_ids, true ) );

    if( is_array( $default_question_ids ) && 0 < count( $default_question_ids ) ){
      foreach( $default_question_ids as $ID ){
        $term = get_term( $ID );
        $terms[ $term->term_order ] = $term;
      }
    }
    if( WP_CLI && true === WP_CLI_TEST && class_exists( 'WP_CLI' ) )
      \WP_CLI::line( '🔔 get_screening_questions() $terms = ' . print_r( $terms, true ) );
  }
  foreach( $terms as $term ) {
    $screening_questions[ $term->term_order ] = array( 'id' => $term->term_id, 'name' => $term->name, 'desc' => $term->description );
  }
  ksort( $screening_questions );

  return $screening_questions;
}

/**
 * Checks if a donation is `orphaned`.
 *
 * In order for this function to return `true`, orphaned
 * donation routing must be ON, and the donation must be
 * using the default pick up provider.
 *
 * @access DonationManager\emails\{send_email}
 * @since 1.3.0
 *
 * @param int $donor_trans_dept_id Trans dept ID associated with donation.
 * @return bool Returns `true` for orphaned donations.
 */
function is_orphaned_donation( $donor_trans_dept_id = 0 ){
  $orphaned_donation_routing = get_field( 'orphaned_donation_routing', 'option' );
  $default_trans_dept = get_field( 'default_transportation_department', 'option' );

  if(
      true == $orphaned_donation_routing
      && $donor_trans_dept_id == $default_trans_dept->ID
  ){
      return true;
  } else {
      return false;
  }
}