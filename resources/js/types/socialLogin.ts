import type { SettingFieldView } from './shipping';

export type SocialLoginRow = {
  id: string;
  provider: string;
  provider_name: string;
  name: string;
  active: boolean;
  complete: boolean;
  version: number;
};

export type SocialLoginEditor = {
  id: string;
  provider: string;
  name: string;
  active: boolean;
  version: number;
  fields: SettingFieldView[];
};

/** A supported provider with its empty field definitions and registration details. */
export type SocialLoginCatalogEntry = {
  id: string;
  name: string;
  fields: SettingFieldView[];
  callback_url: string;
  console_url: string;
};
