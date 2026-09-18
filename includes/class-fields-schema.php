<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Static-only data class — no hooks, never instantiated. Every other module
 * reads the field schema, payment settings, and status list from here, so
 * this is the one place to add/remove a field or change a default.
 */
class Reunion_Reg_Fields_Schema {

    /**
     * Field schema — single source of truth.
     * Add/remove fields here and the form + admin view + storage all update.
     *
     * Supported keys per field:
     *   section     (int)    Groups fields under a numbered heading on the public form (1-4).
     *   label       (string) Field label.
     *   type        (string) text | tel | email | select | radio | number | computed | file
     *   required    (bool)   Whether the field must be filled — ignored when depends_on doesn't match.
     *   options     (array)  Choices for select/radio (value === label, kept human-readable in CSV/admin).
     *   placeholder (string) Input placeholder, or the "-- select --" prompt for select fields.
     *   default     (string|number) Pre-selected/pre-filled value shown on first load.
     *   note        (string) Small helper text rendered under the field.
     *   min/max     (number) Bounds for number fields (clamped server-side, never trusted from the client).
     *   depends_on  (array)  array('field' => <other field key>, 'value' => <required value>) —
     *                        field is only shown/required/validated when the referenced field
     *                        currently holds that value. Must reference a field declared earlier
     *                        in this array.
     *
     * 'computed' fields (currently just total_amount) are never read from $_POST — they are
     * always (re)calculated server-side from get_payment_settings() so a tampered client value
     * can never change what gets charged/recorded.
     */
    public static function get_fields() {
        $batch_years = range( 2025, 1970 ); // newest first

        $education_levels = array( 'এসএসসি', '১০ম শ্রেণি', '৯ম শ্রেণি', '৮ম শ্রেণি', '৭ম শ্রেণি', '৬ষ্ঠ শ্রেণি' );
        $blood_groups      = array( 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-' );
        $professions       = array( 'চাকুরীজীবী', 'ব্যবসায়ী', 'গৃহিণী', 'কৃষক', 'অবসরপ্রাপ্ত', 'ছাত্র/ছাত্রী', 'প্রবাসী', 'অন্যান্য' );
        $gift_options      = array( 'T-Shirt (S)', 'T-Shirt (M)', 'T-Shirt (L)', 'T-Shirt (XL)', 'T-Shirt (XXL)', 'শাড়ি (Sari)' );
        $payment_channels  = array( 'মোবাইল ব্যাংকিং', 'ব্যাংক একাউন্ট' );
        $mobile_methods    = self::get_mobile_banking_method_options();

        return array(

            // ---------- ১. ব্যক্তিগত বিবরণ ----------
            'full_name'           => array(
                'section' => 1, 'label' => 'নাম', 'type' => 'text', 'required' => true,
                'placeholder' => 'আপনার নাম লিখুন',
            ),
            'phone'               => array(
                'section' => 1, 'label' => 'মোবাইল নম্বর', 'type' => 'tel', 'required' => true,
                'placeholder' => 'আপনার ফোন নম্বর লিখুন',
            ),
            'email'               => array(
                'section' => 1, 'label' => 'ইমেইল ঠিকানা', 'type' => 'email', 'required' => true,
                'placeholder' => 'আপনার ইমেইল ঠিকানা লিখুন',
            ),
            'father_husband_name' => array(
                'section' => 1, 'label' => 'পিতার নাম', 'type' => 'text', 'required' => true,
                'placeholder' => 'পিতার নাম লিখুন',
            ),
            'mother_name'         => array(
                'section' => 1, 'label' => 'মাতার নাম', 'type' => 'text', 'required' => true,
                'placeholder' => 'মাতার নাম লিখুন',
            ),
            'batch'               => array(
                'section' => 1, 'label' => 'এসএসসি ব্যাচ', 'type' => 'select', 'required' => true,
                'options' => $batch_years, 'placeholder' => 'সিলেক্ট করুন',
            ),
            'education_level'     => array(
                'section' => 1, 'label' => 'কোন শ্রেণী পর্যন্ত পড়েছেন?', 'type' => 'select', 'required' => true,
                'options' => $education_levels, 'placeholder' => 'সিলেক্ট করুন',
            ),
            'blood_group'         => array(
                'section' => 1, 'label' => 'ব্লাড গ্রুপ (ঐচ্ছিক)', 'type' => 'select', 'required' => false,
                'options' => $blood_groups, 'placeholder' => 'সিলেক্ট করুন',
            ),
            'permanent_address'   => array(
                'section' => 1, 'label' => 'স্থায়ী ঠিকানা', 'type' => 'text', 'required' => true,
                'placeholder' => 'আপনার স্থায়ী ঠিকানা লিখুন',
            ),
            'present_address'     => array(
                'section' => 1, 'label' => 'বর্তমান ঠিকানা', 'type' => 'text', 'required' => true,
                'placeholder' => 'আপনার বর্তমান ঠিকানা লিখুন',
            ),
            'gender'              => array(
                'section' => 1, 'label' => 'লিঙ্গ', 'type' => 'radio', 'required' => true,
                'options' => array( 'পুরুষ', 'মহিলা' ),
            ),
            'marital_status'      => array(
                'section' => 1, 'label' => 'বৈবাহিক অবস্থা', 'type' => 'radio', 'required' => true,
                'options' => array( 'বিবাহিত', 'অবিবাহিত' ),
            ),

            // ---------- ২. পেশাগত বিবরণ ----------
            'profession'        => array(
                'section' => 2, 'label' => 'পেশা', 'type' => 'radio', 'required' => true,
                'options' => $professions,
            ),
            'organization_name' => array(
                'section' => 2, 'label' => 'প্রতিষ্ঠানের নাম', 'type' => 'text', 'required' => false,
                'placeholder' => 'আপনার প্রতিষ্ঠানের নাম লিখুন',
            ),
            'designation'       => array(
                'section' => 2, 'label' => 'পদবী', 'type' => 'text', 'required' => false,
                'placeholder' => 'আপনার পদবী লিখুন',
            ),

            // ---------- ৩. উৎসবের তথ্য ও ফি ----------
            'parking_needed' => array(
                'section' => 3, 'label' => 'পার্কিং সুবিধা প্রয়োজন?', 'type' => 'radio', 'required' => true,
                'options' => array( 'হ্যাঁ', 'না' ),
            ),
            'gift_dress'     => array(
                'section' => 3, 'label' => 'উপহারের পোশাক নির্বাচন করুন', 'type' => 'radio', 'required' => true,
                'options' => $gift_options,
            ),
            'guest_count'    => array(
                'section' => 3, 'label' => 'অতিথির সংখ্যা', 'type' => 'number', 'required' => false,
                'min' => 0, 'default' => 0,
                'note' => '* বাবা, মা, স্ত্রী, সন্তান (প্রতিজন ৫০০/-)',
            ),
            'donation'       => array(
                'section' => 3, 'label' => 'অনুদান (ঐচ্ছিক)', 'type' => 'number', 'required' => false,
                'min' => 0, 'default' => 0,
                'note' => '* অতিরিক্ত দিতে ইচ্ছুক হলে লিখুন',
            ),
            'total_amount'   => array(
                'section' => 3, 'label' => 'সর্বমোট প্রদেয় টাকা (Total Amount)', 'type' => 'computed', 'required' => false,
            ),

            // ---------- ৪. পেমেন্ট পদ্ধতি ----------
            'payment_channel'        => array(
                'section' => 4, 'label' => 'পেমেন্ট মাধ্যম সিলেক্ট করুন', 'type' => 'radio', 'required' => true,
                'options' => $payment_channels, 'default' => 'মোবাইল ব্যাংকিং',
            ),
            'mobile_banking_method'  => array(
                'section' => 4, 'label' => 'কোন মাধ্যমে দিয়েছেন?', 'type' => 'select', 'required' => true,
                'options' => $mobile_methods, 'placeholder' => 'নির্বাচন করুন',
                'depends_on' => array( 'field' => 'payment_channel', 'value' => 'মোবাইল ব্যাংকিং' ),
            ),
            'bank_name'              => array(
                'section' => 4, 'label' => 'ব্যাংকের নাম', 'type' => 'text', 'required' => true,
                'placeholder' => 'যে ব্যাংক থেকে জমা দিয়েছেন',
                'depends_on' => array( 'field' => 'payment_channel', 'value' => 'ব্যাংক একাউন্ট' ),
            ),
            'tnx_id'                 => array(
                'section' => 4, 'label' => 'ট্রানজেকশন আইডি (TxID)', 'type' => 'text', 'required' => true,
                'placeholder' => 'যেমন: BKA3X7R9Z2',
            ),

            // ---------- ৫. আবেদনকারীর ছবি / পেমেন্ট রসিদ ----------
            'applicant_photo'        => array(
                'section' => 5, 'label' => 'আপনার ছবি আপলোড করুন', 'type' => 'file', 'required' => true,
                'accept'  => 'image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp',
                'note'    => 'JPG, PNG বা WebP — সর্বোচ্চ ২ MB (৫-১০ MB বড় ছবি স্বয়ংক্রিয়ভাবে ১২০০px ও ~৮২% কোয়ালিটিতে কম্প্রেস হয়ে ২ MB-এর নিচে নেমে আসবে)',
            ),
        );
    }

    /**
     * Payment numbers + fee amounts, editable from Registrations → Payment & Fee Settings
     * so the client can update bKash/Nagad/Rocket numbers or ticket prices without touching code.
     */
    public static function get_payment_settings() {
        $defaults = array(
            'registration_fee' => 1000,
            'guest_fee'        => 500,
            'person1_name'     => 'Person 1',
            'person1_number'   => '',
            'person2_name'     => 'Person 2',
            'person2_number'   => '',
            'person3_name'     => 'Person 3',
            'person3_number'   => '',
            'bank_details'     => '',
            'terms_url'        => '',
        );

        $settings = wp_parse_args( get_option( REUNION_REG_PAYMENT_SETTINGS_OPTION, array() ), $defaults );

        if ( empty( $settings['person1_number'] ) && empty( $settings['person2_number'] ) && empty( $settings['person3_number'] ) ) {
            $legacy = get_option( REUNION_REG_PAYMENT_SETTINGS_OPTION, array() );
            if ( ! empty( $legacy['bkash_number'] ) && empty( $settings['person1_number'] ) ) {
                $settings['person1_number'] = $legacy['bkash_number'];
            }
            if ( ! empty( $legacy['nagad_number'] ) && empty( $settings['person2_number'] ) ) {
                $settings['person2_number'] = $legacy['nagad_number'];
            }
            if ( ! empty( $legacy['rocket_number'] ) && empty( $settings['person3_number'] ) ) {
                $settings['person3_number'] = $legacy['rocket_number'];
            }
        }

        return $settings;
    }

    public static function get_mobile_banking_method_options() {
        $settings = self::get_payment_settings();
        $persons = array(
            array( 'label' => 'P1', 'number' => $settings['person1_number'] ),
            array( 'label' => 'P2', 'number' => $settings['person2_number'] ),
            array( 'label' => 'P3', 'number' => $settings['person3_number'] ),
        );
        $gateways = array( 'বিকাশ', 'নগদ', 'রকেট' );
        $options  = array();

        foreach ( $persons as $person ) {
            $digits = preg_replace( '/\D/', '', (string) $person['number'] );
            $full   = $digits ?: '—';
            foreach ( $gateways as $gw ) {
                $options[] = sprintf( '%s - %s (%s)', $gw, $full, $person['label'] );
            }
        }

        return $options;
    }

    /**
     * Status helpers — single source of truth for the 3 possible states.
     */
    public static function get_statuses() {
        return array(
            'pending'  => 'Pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
        );
    }

    /**
     * Whether a field is currently relevant given its 'depends_on' rule (if any).
     * Shared by the form renderer (decides visibility) and the submission
     * handler (decides whether 'required' should be enforced) — living here,
     * next to the schema that defines 'depends_on' in the first place, avoids
     * either of those two unrelated modules having to call into the other.
     */
    public static function field_is_applicable( $field, $context_values ) {
        if ( empty( $field['depends_on'] ) ) {
            return true;
        }

        $dep_field = $field['depends_on']['field'];
        $dep_value = $field['depends_on']['value'];
        $current   = isset( $context_values[ $dep_field ] ) ? $context_values[ $dep_field ] : '';

        return ( (string) $current === (string) $dep_value );
    }
}
