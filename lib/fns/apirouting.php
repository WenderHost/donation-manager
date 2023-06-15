<?php

namespace DonationManager\apirouting;
use function DonationManager\organizations\{get_organizations};

/**
 * Sends a donation directly to a third-party API.
 *
 * @since 1.4.1
 *
 * @param array $donation The donation array.
 * @return void
 */
function send_api_post( $donation ){
  if( DONMAN_DEV_ENV ){
    uber_log('🔔 We are in Development Mode, not sending API Post.');
    return true;
  }

  switch( $donation['routing_method'] ){
    case 'chhj_api':
    case 'api-chhj':
      $is_chhj_pickupcode = is_valid_pickupcode( 'College Hunks', $donation['pickup_code'] );
      if( $is_valid_pickupcode ){
        require_once DONMAN_PLUGIN_PATH . 'lib/classes/donation-router.php';
        require_once DONMAN_PLUGIN_PATH . 'lib/classes/donation-router.chhj.php';
        $CHHJDonationRouter = \CHHJDonationRouter::get_instance();
        $CHHJDonationRouter->submit_donation( $donation );
        return true;
      }
    break;
  }
}

/**
 * Determines if a pickup code is valid by checking if it has been assigned to an organization.
 *
 * @param      string  $search       The search string
 * @param      string  $pickup_code  The pickup code
 *
 * @return     bool    True if valid pickupcode, False otherwise.
 */
function is_valid_pickupcode( $search = 'College Hunks', $pickup_code = null ){
  $valid = false;
  if( is_null( $pickup_code ) || empty( $pickup_code ) )
    return $valid;

  $organizations = get_organizations( $pickup_code );
  switch( $search ){
    default:
      foreach ( $organizations as $organization ) {
        if( stristr( $organization['name'], $search ) )
          $valid = true;
      }
      break;
  }

  return $valid;
}