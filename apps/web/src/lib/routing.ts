import type { Me } from "./api";

/**
 * Where a signed-in person lands. Anyone who runs the platform goes to the
 * admin console; a reviewer to the review queue; everyone else is a student.
 */
export function homeFor(me: Pick<Me, "roles"> & { is_platform_admin?: boolean }): string {
  const roles = me.roles ?? [];

  if (me.is_platform_admin || roles.some((r) => ["super_admin", "platform_admin", "organization_admin", "teacher"].includes(r))) {
    return "/admin/dashboard";
  }

  if (roles.includes("content_reviewer")) return "/review";

  return "/app";
}
