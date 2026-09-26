import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import { faFacebook, faGoogle, faLinkedin, faMeta, faTelegram, faViber, faWhatsapp } from '@fortawesome/free-brands-svg-icons';
import {
  faBrain,
  faBriefcase,
  faBullhorn,
  faCalendarDays,
  faCircleQuestion,
  faEnvelope,
  faFileImport,
  faFileSignature,
  faGear,
  faGlobe,
  faInbox,
  faNoteSticky,
  faPen,
  faPhone,
  faPhoneVolume,
  faPlug,
  faPuzzlePiece,
  faRightLeft,
  faRobot,
  faTable,
  faUserGroup,
  faVideo,
  faWaveSquare,
} from '@fortawesome/free-solid-svg-icons';

/** How one channel / source / integration is drawn: Font Awesome icon, optional brand color and letter badge. */
export interface ChannelIconSpec {
  icon: IconDefinition;
  /** Brand color; `null` = inherit the text color (generic, non-brand icons). */
  color: string | null;
  /** Short badge for job boards that have no brand icon in Font Awesome Free (work.ua → "W"). */
  badge: string | null;
}

const spec = (icon: IconDefinition, color: string | null = null, badge: string | null = null): ChannelIconSpec => ({ icon, color, badge });

// Official brand colors (brand guidelines of each service); generic actions keep the text color.
const TELEGRAM = spec(faTelegram, '#26A5E4');
const WHATSAPP = spec(faWhatsapp, '#25D366');
const VIBER = spec(faViber, '#7360F2');
const META = spec(faMeta, '#0467DF');
const TELEPHONY = spec(faPhoneVolume);
const GMAIL = spec(faEnvelope, '#EA4335');
const SHEETS = spec(faTable, '#0F9D58');
const CALENDAR = spec(faCalendarDays, '#4285F4');

/**
 * Keys of timeline channels, candidate sources, "added via", integration keys (Admin → Integrations) and Google
 * services share one map, so the same resource always looks the same across the app.
 */
export const CHANNEL_ICON_SPECS: Readonly<Record<string, ChannelIconSpec>> = {
  // Messengers & touch channels
  telegram: TELEGRAM,
  telegram_business: TELEGRAM,
  whatsapp: WHATSAPP,
  whatsapp_cloud: WHATSAPP,
  wazzup: WHATSAPP,
  viber: VIBER,
  email: spec(faEnvelope),
  mail: spec(faEnvelope),
  call: spec(faPhone),
  meeting: spec(faVideo),
  note: spec(faNoteSticky),
  system: spec(faGear),
  // Job boards and ads
  linkedin: spec(faLinkedin, '#0A66C2'),
  work_ua: spec(faBriefcase, null, 'W'),
  robota_ua: spec(faBriefcase, null, 'R'),
  djinni: spec(faBriefcase, null, 'Dj'),
  dou: spec(faBriefcase, null, 'D'),
  meta_ads: META,
  meta_lead_ads: META,
  facebook: spec(faFacebook, '#0866FF'),
  // Google Workspace
  google: spec(faGoogle, '#4285F4'),
  gmail: GMAIL,
  google_gmail: GMAIL,
  sheets: SHEETS,
  google_sheets: SHEETS,
  calendar: CALENDAR,
  google_calendar: CALENDAR,
  // Telephony
  phonet: TELEPHONY,
  ringostat: TELEPHONY,
  binotel: TELEPHONY,
  // AI & speech
  openrouter: spec(faRobot),
  ai_broker: spec(faBrain),
  deepgram: spec(faWaveSquare),
  // Other integrations
  kep_signing: spec(faFileSignature),
  // Own sources / "added via"
  manual: spec(faPen),
  site: spec(faGlobe),
  referral: spec(faUserGroup),
  import: spec(faFileImport),
  inbox: spec(faInbox),
  extension: spec(faPuzzlePiece),
  webhook: spec(faPlug),
  campaign: spec(faBullhorn),
  // Timeline stage change (not a channel, but drawn in the same list)
  stage: spec(faRightLeft),
};

const FALLBACK = spec(faCircleQuestion);

/** The icon for a key; unknown keys (a new backend value) get a neutral question mark instead of breaking. */
export function channelIconSpec(key: string | null | undefined): ChannelIconSpec {
  return (key && Object.hasOwn(CHANNEL_ICON_SPECS, key) ? CHANNEL_ICON_SPECS[key] : null) ?? FALLBACK;
}

/** True when the key has its own icon (for free-form codes, e.g. acquisition channels: show nothing otherwise). */
export function hasChannelIcon(key: string | null | undefined): boolean {
  return !!key && Object.hasOwn(CHANNEL_ICON_SPECS, key);
}
