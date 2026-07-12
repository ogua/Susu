package db;

import models.LocalUser;

/**
 * Holds the current desktop session. Role checks mirror the backend's
 * Spatie role names.
 */
public class SessionManager {

    private static volatile LocalUser currentUser;

    private SessionManager() {}

    public static void setCurrentUser(LocalUser user) {
        currentUser = user;
    }

    public static LocalUser getCurrentUser() {
        return currentUser;
    }

    public static void clearSession() {
        currentUser = null;
    }

    public static boolean isLoggedIn() {
        return currentUser != null;
    }

    public static boolean hasRole(String... roles) {
        if (currentUser == null || currentUser.getRole() == null) return false;
        String userRole = currentUser.getRole().toLowerCase();
        for (String r : roles) {
            if (userRole.equals(r.toLowerCase())) return true;
        }
        return false;
    }

    public static boolean canManageUsers() {
        return hasRole("company_admin", "super_admin");
    }
}
