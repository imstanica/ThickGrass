<?php

namespace ThickGrass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-organization, per-weekday working hours used for SLA calculations.
 * See PLAN.md section 3.2.
 */
class Business_Hours {

	private const DEFAULT_START = '09:00';
	private const DEFAULT_END   = '17:00';

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'thickgrass_business_hours';
	}

	/**
	 * Always returns exactly 7 rows (0 = Sunday ... 6 = Saturday), filling in
	 * sensible Mon-Fri defaults for any weekday that has not been saved yet.
	 *
	 * @return array<int, object>
	 */
	public static function get_for_organization( int $organization_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE organization_id = %d", $organization_id )
		);

		$by_weekday = [];
		foreach ( $rows as $row ) {
			$by_weekday[ (int) $row->weekday ] = $row;
		}

		$result = [];
		for ( $weekday = 0; $weekday <= 6; $weekday++ ) {
			if ( isset( $by_weekday[ $weekday ] ) ) {
				$result[ $weekday ] = $by_weekday[ $weekday ];
				continue;
			}

			$is_working_day = $weekday >= 1 && $weekday <= 5;

			$result[ $weekday ] = (object) [
				'organization_id' => $organization_id,
				'weekday'         => $weekday,
				'is_working_day'  => $is_working_day ? 1 : 0,
				'start_time'      => self::DEFAULT_START,
				'end_time'        => self::DEFAULT_END,
			];
		}

		return $result;
	}

	/**
	 * @param array<int, array{is_working_day?: bool, start_time?: string, end_time?: string}> $days keyed by weekday (0-6)
	 */
	public static function save_for_organization( int $organization_id, array $days ): void {
		global $wpdb;

		$table = self::table();

		for ( $weekday = 0; $weekday <= 6; $weekday++ ) {
			$day = $days[ $weekday ] ?? [];

			$record = [
				'organization_id' => $organization_id,
				'weekday'         => $weekday,
				'is_working_day'  => ! empty( $day['is_working_day'] ) ? 1 : 0,
				'start_time'      => sanitize_text_field( $day['start_time'] ?? self::DEFAULT_START ),
				'end_time'        => sanitize_text_field( $day['end_time'] ?? self::DEFAULT_END ),
			];

			$existing_id = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE organization_id = %d AND weekday = %d", $organization_id, $weekday )
			);

			if ( $existing_id ) {
				$wpdb->update( $table, $record, [ 'id' => (int) $existing_id ] );
			} else {
				$wpdb->insert( $table, $record );
			}
		}
	}

	/**
	 * Adds $minutes of *working* time to $from, skipping non-working days and
	 * time outside the configured start/end window - used for SLA due dates.
	 * Caps at 365 days to guarantee termination if an organization has no
	 * working day configured at all (falls back to a plain calendar-time add).
	 */
	public static function add_minutes( int $organization_id, \DateTimeImmutable $from, int $minutes ): \DateTimeImmutable {
		if ( $minutes <= 0 ) {
			return $from;
		}

		$schedule  = self::get_for_organization( $organization_id );
		$remaining = $minutes;
		$cursor    = $from;

		for ( $day = 0; $day < 365; $day++ ) {
			$weekday      = (int) $cursor->format( 'w' );
			$day_schedule = $schedule[ $weekday ];

			if ( ! empty( $day_schedule->is_working_day ) ) {
				$window_start  = self::combine_date_and_time( $cursor, $day_schedule->start_time );
				$window_end    = self::combine_date_and_time( $cursor, $day_schedule->end_time );
				$segment_start = $cursor > $window_start ? $cursor : $window_start;

				if ( $segment_start < $window_end ) {
					$available = (int) floor( ( $window_end->getTimestamp() - $segment_start->getTimestamp() ) / 60 );

					if ( $available >= $remaining ) {
						return $segment_start->modify( "+{$remaining} minutes" );
					}

					$remaining -= $available;
				}
			}

			$cursor = $cursor->modify( '+1 day' )->setTime( 0, 0 );
		}

		return $from->modify( "+{$minutes} minutes" );
	}

	private static function combine_date_and_time( \DateTimeImmutable $date, string $time ): \DateTimeImmutable {
		return new \DateTimeImmutable( $date->format( 'Y-m-d' ) . ' ' . $time, $date->getTimezone() );
	}
}
