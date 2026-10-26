<?php
/**
 * The bottom of every presale e-mail: how to reach the business -- replying reaches it, the Reply-To
 * is its address (K2) -- and why this e-mail is not advertising (funcional §4.6).
 *
 * @var array $config
 */
$company = (string) ($config['company'] ?? '');
$phone   = (string) ($config['phone'] ?? '');
?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 16px 24px 20px; border-top: 1px solid #e4e4e7; font-size: 12px; color: #57606a; line-height: 1.5;">
                        <p style="margin: 0 0 6px;"><?= esc($phone === '' ? lang('Presales.mail_contact', [$company]) : lang('Presales.mail_contact_phone', [$company, $phone])) ?></p>
                        <p style="margin: 0;"><?= esc(lang('Presales.mail_not_advertising')) ?></p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
