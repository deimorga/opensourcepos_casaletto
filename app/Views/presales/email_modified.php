<?php
/**
 * E-mail: the presale was changed (funcional §4.17). The same body and document as the registration,
 * as the presale stands now, with its own heading and intro.
 *
 * @var array<string, mixed>       $presale  Presale::get_summary()
 * @var object                     $customer
 * @var string                     $campaign_name
 * @var list<array<string, mixed>> $lines
 * @var list<array<string, mixed>> $installments
 * @var array                      $config
 * @var bool                       $has_logo
 */
?>
<?= view('presales/email_created', [
    'presale'       => $presale,
    'customer'      => $customer,
    'campaign_name' => $campaign_name,
    'lines'         => $lines,
    'installments'  => $installments,
    'config'        => $config,
    'has_logo'      => $has_logo,
    'mail_kind'     => 'modified',
], ['saveData' => false]) ?>
