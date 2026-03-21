<?php

namespace App\Support;

class Permissions
{
    const MANAGE_SERVER       = 'manage_server';
    const MANAGE_ROLES        = 'manage_roles';
    const VIEW_AUDIT_LOG      = 'view_audit_log';
    const APPROVE_MEMBERS     = 'approve_members';
    const KICK_MEMBERS        = 'kick_members';
    const BAN_MEMBERS         = 'ban_members';
    const MANAGE_MEMBER_ROLES = 'manage_member_roles';
    const MANAGE_CHANNELS     = 'manage_channels';
    const SEND_MESSAGES       = 'send_messages';
    const ATTACH_FILES        = 'attach_files';
    const DELETE_ANY_MESSAGE  = 'delete_any_message';
    const PIN_MESSAGES        = 'pin_messages';
    const MENTION_EVERYONE    = 'mention_everyone';
    // Voice
    const JOIN_VOICE          = 'join_voice';
    const SPEAK               = 'speak';
    const VIDEO               = 'video';
    const MUTE_MEMBERS        = 'mute_members';
    const DEAFEN_MEMBERS      = 'deafen_members';
    const MOVE_MEMBERS        = 'move_members';
    const PRIORITY_SPEAKER    = 'priority_speaker';

    const ALL = [
        self::MANAGE_SERVER, self::MANAGE_ROLES, self::VIEW_AUDIT_LOG,
        self::APPROVE_MEMBERS, self::KICK_MEMBERS, self::BAN_MEMBERS, self::MANAGE_MEMBER_ROLES,
        self::MANAGE_CHANNELS,
        self::SEND_MESSAGES, self::ATTACH_FILES, self::DELETE_ANY_MESSAGE, self::PIN_MESSAGES,
        self::MENTION_EVERYONE,
        self::JOIN_VOICE, self::SPEAK, self::VIDEO,
        self::MUTE_MEMBERS, self::DEAFEN_MEMBERS, self::MOVE_MEMBERS, self::PRIORITY_SPEAKER,
    ];

    const CATEGORIES = [
        'Server' => [
            self::MANAGE_SERVER  => 'Manage server settings',
            self::MANAGE_ROLES   => 'Manage roles and permissions',
            self::VIEW_AUDIT_LOG => 'View audit log',
        ],
        'Members' => [
            self::APPROVE_MEMBERS     => 'Approve join requests',
            self::KICK_MEMBERS        => 'Kick members',
            self::BAN_MEMBERS         => 'Ban members',
            self::MANAGE_MEMBER_ROLES => 'Assign roles to members',
        ],
        'Channels' => [
            self::MANAGE_CHANNELS => 'Manage channels and sections',
        ],
        'Messages' => [
            self::SEND_MESSAGES      => 'Send messages',
            self::ATTACH_FILES       => 'Attach files',
            self::DELETE_ANY_MESSAGE => 'Delete any message',
            self::PIN_MESSAGES       => 'Pin messages',
            self::MENTION_EVERYONE   => 'Use @everyone and @here',
        ],
        'Voice' => [
            self::JOIN_VOICE       => 'Connect to voice channels',
            self::SPEAK            => 'Speak in voice channels',
            self::VIDEO            => 'Share video / screen',
            self::MUTE_MEMBERS     => 'Server mute members',
            self::DEAFEN_MEMBERS   => 'Server deafen members',
            self::MOVE_MEMBERS     => 'Move members between voice channels',
            self::PRIORITY_SPEAKER => 'Priority speaker (voice ducking)',
        ],
    ];

    const MEMBER_DEFAULTS = [
        self::SEND_MESSAGES, self::ATTACH_FILES,
        self::JOIN_VOICE, self::SPEAK, self::VIDEO,
    ];
}
