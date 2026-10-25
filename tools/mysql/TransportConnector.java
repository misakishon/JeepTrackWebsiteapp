import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;
import java.sql.*;
import java.util.*;

/** Connector/J setup tool. Laravel's HTTP runtime uses PHP PDO_MYSQL. */
class TransportConnector {
    static String read(BufferedReader input) throws Exception {
        String line = input.readLine();
        if (line == null) throw new IllegalArgumentException("Incomplete setup input.");
        return new String(Base64.getDecoder().decode(line), StandardCharsets.UTF_8);
    }
    static List<String> statements(String sql) {
        List<String> result = new ArrayList<>(); StringBuilder current = new StringBuilder(); char quote = 0;
        for(int i=0;i<sql.length();i++) {
            char ch=sql.charAt(i);
            if(quote!=0) {
                current.append(ch);
                if(ch=='\\'&&i+1<sql.length()) {current.append(sql.charAt(++i));continue;}
                if(ch==quote) {if(i+1<sql.length()&&sql.charAt(i+1)==quote)current.append(sql.charAt(++i));else quote=0;}
            } else if(ch=='\''||ch=='"'||ch=='`') {quote=ch;current.append(ch);}
            else if(ch==';') {if(!current.toString().trim().isEmpty())result.add(current.toString().trim());current.setLength(0);}
            else current.append(ch);
        }
        if(quote!=0)throw new IllegalArgumentException("Unterminated SQL quote.");
        if(!current.toString().trim().isEmpty())result.add(current.toString().trim());
        return result;
    }
    public static void main(String[] args) {
        try {
            BufferedReader input=new BufferedReader(new InputStreamReader(System.in, StandardCharsets.UTF_8));
            String host=read(input),port=read(input),database=read(input),username=read(input),password=read(input),action=read(input),sql=read(input);
            if(!database.matches("[A-Za-z0-9_]+")||!port.matches("[0-9]+")||!host.matches("[A-Za-z0-9._:-]+"))throw new IllegalArgumentException("Invalid connection configuration.");
            Class.forName("com.mysql.cj.jdbc.Driver");
            Properties properties=new Properties();properties.setProperty("user",username);properties.setProperty("password",password);properties.setProperty("connectTimeout","5000");properties.setProperty("socketTimeout","15000");properties.setProperty("connectionTimeZone","UTC");
            // No blanket allowPublicKeyRetrieval or disabled TLS settings.
            try(Connection connection=DriverManager.getConnection("jdbc:mysql://"+host+":"+port+"/",properties);Statement statement=connection.createStatement()) {
                if(action.equals("import")) {
                    List<String> prepared=statements(sql);
                    if(prepared.isEmpty())throw new IllegalArgumentException("No SQL statements were supplied.");
                    for(String part:prepared)if(!part.toUpperCase(Locale.ROOT).matches("(?s)^(CREATE\\s+TABLE|INSERT\\s+INTO)\\s+.*"))throw new IllegalArgumentException("Unsafe import statement rejected.");
                    statement.executeUpdate("CREATE DATABASE IF NOT EXISTS `"+database+"` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    statement.execute("USE `"+database+"`");
                    try(ResultSet tables=statement.executeQuery("SHOW TABLES")) {if(tables.next())throw new IllegalArgumentException("Import refused: the destination already contains tables. Existing tables were not changed.");}
                    for(String part:prepared)statement.executeUpdate(part);
                    System.out.println("Imported the supplied schema through MySQL Connector/J. Passwords were hashed before import.");
                } else if(action.equals("check")) {statement.execute("USE `"+database+"`");try(ResultSet check=statement.executeQuery("SELECT 1")){check.next();}System.out.println("Connector/J connection verified: MySQL "+connection.getMetaData().getDatabaseProductVersion());}
                else throw new IllegalArgumentException("Unknown setup action.");
            }
        } catch(SQLException e) {
            System.err.println("MySQL connection/import failed (SQLState "+e.getSQLState()+", code "+e.getErrorCode()+"). Check the server, credentials, permissions, and schema. No credentials are logged.");System.exit(1);
        } catch(Exception e) {
            System.err.println(e instanceof IllegalArgumentException?e.getMessage():"Connector/J could not start. Verify Java 11+ and the supplied JAR path.");System.exit(1);
        }
    }
}
